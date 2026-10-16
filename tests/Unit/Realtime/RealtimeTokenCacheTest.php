<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Spooled\Config\ClientOptions;
use Spooled\Http\HttpClient;
use Spooled\Realtime\SpooledRealtime;
use Spooled\Resources\AuthResource;
use Spooled\SpooledClient;
use Spooled\Tests\Support\HttpClientFactory;

/**
 * Unit tests for the cached API-key -> JWT exchange used by realtime().
 *
 * These mock the HTTP client and drive the private token-exchange helper via
 * reflection, so they make no network calls and run in the default unit suite.
 */
#[CoversClass(SpooledClient::class)]
final class RealtimeTokenCacheTest extends TestCase
{
    #[Test]
    public function exchangeLogsInOnceWhenCalledTwiceWithinTokenLifetime(): void
    {
        $jwt = $this->makeJwt(time() + 3600);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('post')
            ->with('auth/login', ['apiKey' => 'sp_test_key'])
            ->willReturn(['accessToken' => $jwt, 'refreshToken' => 'refresh', 'expiresIn' => 3600]);

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));

        $first = $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);
        $second = $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);

        // Both calls return the same JWT and only ONE login happened (asserted by
        // the once() expectation on the mock).
        $this->assertSame($jwt, $first);
        $this->assertSame($jwt, $second);
    }

    #[Test]
    public function expiredCachedTokenTriggersReLogin(): void
    {
        $freshJwt = $this->makeJwt(time() + 3600);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('post')
            ->with('auth/login')
            ->willReturn(['accessToken' => $freshJwt, 'refreshToken' => 'refresh', 'expiresIn' => 3600]);

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));

        // Seed an already-expired cached token.
        $this->setPrivate($client, 'cachedRealtimeToken', $this->makeJwt(time() - 10));
        $this->setPrivate($client, 'cachedRealtimeTokenExpiresAt', time() - 10);

        $result = $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);

        $this->assertSame($freshJwt, $result);
    }

    #[Test]
    public function nearExpiryCachedTokenTriggersReLoginWithinLeeway(): void
    {
        $freshJwt = $this->makeJwt(time() + 3600);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('post')
            ->with('auth/login')
            ->willReturn(['accessToken' => $freshJwt, 'refreshToken' => 'refresh', 'expiresIn' => 3600]);

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));

        // Expires in 30s, which is inside the 60s refresh leeway, so it must re-login.
        $this->setPrivate($client, 'cachedRealtimeToken', $this->makeJwt(time() + 30));
        $this->setPrivate($client, 'cachedRealtimeTokenExpiresAt', time() + 30);

        $result = $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);

        $this->assertSame($freshJwt, $result);
    }

    #[Test]
    public function validCachedTokenIsReusedWithoutLogin(): void
    {
        $validJwt = $this->makeJwt(time() + 3600);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('post');

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));

        $this->setPrivate($client, 'cachedRealtimeToken', $validJwt);
        $this->setPrivate($client, 'cachedRealtimeTokenExpiresAt', time() + 3600);

        $result = $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);

        $this->assertSame($validJwt, $result);
    }

    #[Test]
    public function cacheExpiryIsDecodedFromTheJwtExpClaim(): void
    {
        $exp = time() + 1800;
        // Response carries no expiresIn and no refreshToken: expiry must come from
        // the JWT payload, and a null refresh token must not blow up.
        $jwt = $this->makeJwt($exp);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('post')
            ->with('auth/login')
            ->willReturn(['accessToken' => $jwt]);

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));

        $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);

        $this->assertSame($exp, $this->getPrivate($client, 'cachedRealtimeTokenExpiresAt'));
    }

    #[Test]
    public function configuredStaticAccessTokenIsUsedVerbatimWithoutLogin(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('post');

        $client = $this->makeClient(
            $http,
            new ClientOptions(apiKey: 'sp_test_key', accessToken: 'static-access-token'),
        );

        $result = $this->invokePrivate($client, 'resolveRealtimeAccessToken', []);

        $this->assertSame('static-access-token', $result);
    }

    // --- SDKGP-25: realtime must not hijack the REST bearer ---

    #[Test]
    public function exchangeNeverReplacesTheRestClientCredentials(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('post')->willReturn([
            'accessToken' => $this->makeJwt(time() + 3600),
            'refreshToken' => 'refresh',
            'expiresIn' => 3600,
        ]);
        $http->expects($this->never())->method('setAccessToken');
        $http->expects($this->never())->method('setRefreshToken');

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key']);
    }

    #[Test]
    public function restRequestsKeepTheApiKeyAsBearerAfterRealtimeExchangedAJwt(): void
    {
        $history = [];
        $jwt = $this->makeJwt(time() + 3600);
        $http = HttpClientFactory::create(
            [
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'access_token' => $jwt,
                    'refresh_token' => 'refresh',
                    'expires_in' => 3600,
                ])),
                new Response(200, ['Content-Type' => 'application/json'], '[]'),
            ],
            $history,
            apiKey: 'sp_test_key',
        );
        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));

        $this->assertSame($jwt, $this->invokePrivate($client, 'resolveRealtimeAccessToken', []));
        $http->get('jobs');

        $this->assertSame('Bearer sp_test_key', $history[1]['request']->getHeaderLine('Authorization'));
        $this->assertNull($http->getAccessToken());
    }

    #[Test]
    public function realtimeMakesNoNetworkCallUntilATransportConnects(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('post');

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $realtime = $client->realtime();

        $this->assertInstanceOf(SpooledRealtime::class, $realtime);
        $this->assertSame($realtime, $client->realtime());
        $realtime->sse();
        $realtime->ws();
    }

    #[Test]
    public function sseAuthenticatesWithTheApiKeyAndNeverNeedsAJwt(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('post');

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $headers = $this->invokePrivate($client->realtime()->sse(), 'buildHeaders', []);

        $this->assertSame('Bearer sp_test_key', $headers['Authorization']);
    }

    #[Test]
    public function aJwtTheCallerSetIsUsedByBothTransportsWithoutALogin(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('getAccessToken')->willReturn('caller-jwt');
        $http->expects($this->never())->method('post');

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $realtime = $client->realtime();

        $this->assertSame('Bearer caller-jwt', $this->invokePrivate($realtime->sse(), 'buildHeaders', [])['Authorization']);
        $this->assertStringEndsWith('?token=caller-jwt', $this->invokePrivate($realtime->ws(), 'buildUrl', []));
    }

    #[Test]
    public function theWebSocketGetsAFreshJwtOnReconnectOnceTheCachedOneExpires(): void
    {
        $first = $this->makeJwt(time() + 3600);
        $second = $this->makeJwt(time() + 7200);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->exactly(2))
            ->method('post')
            ->with('auth/login')
            ->willReturnOnConsecutiveCalls(
                ['accessToken' => $first, 'expiresIn' => 3600],
                ['accessToken' => $second, 'expiresIn' => 7200],
            );

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $ws = $client->realtime()->ws();

        $this->assertStringEndsWith('?token=' . $first, $this->invokePrivate($ws, 'buildUrl', []));
        // Same token while valid: no second login.
        $this->assertStringEndsWith('?token=' . $first, $this->invokePrivate($ws, 'buildUrl', []));

        // Time passes: the cached JWT is inside the refresh leeway.
        $this->setPrivate($client, 'cachedRealtimeTokenExpiresAt', time() + 30);

        $this->assertStringEndsWith('?token=' . $second, $this->invokePrivate($ws, 'buildUrl', []));
    }

    #[Test]
    public function forceRefreshDropsAStillValidCachedJwtAndLogsInAgain(): void
    {
        $cached = $this->makeJwt(time() + 3600);
        $fresh = $this->makeJwt(time() + 7200);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('post')
            ->with('auth/login')
            ->willReturn(['accessToken' => $fresh, 'expiresIn' => 7200]);

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $this->setPrivate($client, 'cachedRealtimeToken', $cached);
        $this->setPrivate($client, 'cachedRealtimeTokenExpiresAt', time() + 3600);

        $result = $this->invokePrivate($client, 'exchangeApiKeyForToken', ['sp_test_key', true]);

        $this->assertSame($fresh, $result);
    }

    #[Test]
    public function websocketClose1008LogsInAgainWhileTheCachedJwtIsValid(): void
    {
        $revoked = $this->makeJwt(time() + 3600);
        $fresh = $this->makeJwt(time() + 7200);

        $http = $this->createMock(HttpClient::class);
        $http->expects($this->exactly(2))
            ->method('post')
            ->with('auth/login')
            ->willReturnOnConsecutiveCalls(
                ['accessToken' => $revoked, 'expiresIn' => 3600],
                ['accessToken' => $fresh, 'expiresIn' => 7200],
            );

        $client = $this->makeClient($http, new ClientOptions(apiKey: 'sp_test_key'));
        $ws = $client->realtime()->ws();

        $this->assertStringEndsWith('?token=' . $revoked, $this->invokePrivate($ws, 'buildUrl', []));

        // Session revoked while the cached JWT is still an hour from expiry.
        $this->assertNull($this->invokePrivate($ws, 'noteDisconnect', [1008]));
        $this->assertStringEndsWith('?token=' . $fresh, $this->invokePrivate($ws, 'buildUrl', []));

        // The fresh token is rejected too: stop, and do not log in a third time.
        $stop = $this->invokePrivate($ws, 'noteDisconnect', [1008]);
        $this->assertIsString($stop);
        $this->assertStringContainsString('1008', $stop);
    }

    /**
     * Build a SpooledClient without running its constructor, wiring in a mocked
     * HTTP client and a real AuthResource around it.
     */
    private function makeClient(HttpClient $http, ClientOptions $options): SpooledClient
    {
        $client = (new ReflectionClass(SpooledClient::class))->newInstanceWithoutConstructor();

        $this->setPrivate($client, 'options', $options);
        $this->setPrivate($client, 'logger', new NullLogger());
        $this->setPrivate($client, 'httpClient', $http);
        $this->setPrivate($client, 'auth', new AuthResource($http));

        return $client;
    }

    /**
     * Build a JWT (base64url header.payload.signature) carrying the given exp claim.
     */
    private function makeJwt(int $exp): string
    {
        $encode = static fn (array $data): string => rtrim(
            strtr(base64_encode((string) json_encode($data)), '+/', '-_'),
            '=',
        );

        return $encode(['alg' => 'HS256', 'typ' => 'JWT'])
            . '.' . $encode(['exp' => $exp, 'sub' => 'user'])
            . '.' . 'signature';
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function getPrivate(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}
