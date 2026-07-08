<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Spooled\Config\ClientOptions;
use Spooled\Http\HttpClient;
use Spooled\Resources\AuthResource;
use Spooled\SpooledClient;

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
