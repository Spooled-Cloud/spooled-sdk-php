<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Spooled\Realtime\SseClient;
use Spooled\Realtime\WebSocketClient;

/**
 * Offline unit tests for realtime data-plane authentication.
 *
 * These build the URL/headers via reflection and make no network calls, so
 * they run in the default suite. They are deliberately NOT tagged with the
 * network-oriented realtime group that phpunit.xml excludes.
 */
final class RealtimeAuthTest extends TestCase
{
    #[Test]
    public function testSseSendsApiKeyAsBearerNotXApiKey(): void
    {
        // The data plane ignores X-API-Key; it authenticates via Bearer.
        $client = new SseClient(
            baseUrl: 'https://api.spooled.cloud',
            apiKey: 'sp_secret',
            accessToken: null,
        );

        $headers = $this->invokePrivate($client, 'buildHeaders');

        $this->assertArrayNotHasKey('X-API-Key', $headers);
        $this->assertSame('Bearer sp_secret', $headers['Authorization'] ?? null);
    }

    #[Test]
    public function testSseAccessTokenTakesPrecedenceAsBearer(): void
    {
        $client = new SseClient(
            baseUrl: 'https://api.spooled.cloud',
            apiKey: 'sp_secret',
            accessToken: 'jwt-token',
        );

        $headers = $this->invokePrivate($client, 'buildHeaders');

        $this->assertSame('Bearer jwt-token', $headers['Authorization'] ?? null);
    }

    #[Test]
    public function testWebSocketUrlUsesJwtToken(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            apiKey: null,
            accessToken: 'jwt-token',
        );

        $url = $this->invokePrivate($client, 'buildUrl');

        $this->assertSame('wss://api.spooled.cloud/api/v1/ws?token=jwt-token', $url);
    }

    #[Test]
    public function testWebSocketUrlNeverFallsBackToApiKey(): void
    {
        // /ws only accepts a JWT in ?token=; the client must never send api_key.
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            apiKey: 'sp_secret',
            accessToken: null,
        );

        $url = $this->invokePrivate($client, 'buildUrl');

        $this->assertStringNotContainsString('api_key', $url);
        $this->assertStringNotContainsString('sp_secret', $url);
        $this->assertSame('wss://api.spooled.cloud/api/v1/ws', $url);
    }

    private function invokePrivate(object $object, string $method): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }
}
