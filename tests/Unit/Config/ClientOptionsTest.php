<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Config\CircuitBreakerConfig;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;

#[CoversClass(ClientOptions::class)]
final class ClientOptionsTest extends TestCase
{
    #[Test]
    public function it_uses_default_values(): void
    {
        $options = new ClientOptions();

        $this->assertSame(ClientOptions::DEFAULT_BASE_URL, $options->baseUrl);
        $this->assertSame(ClientOptions::DEFAULT_CONNECT_TIMEOUT, $options->connectTimeout);
        $this->assertSame(ClientOptions::DEFAULT_REQUEST_TIMEOUT, $options->requestTimeout);
        $this->assertSame(ClientOptions::DEFAULT_USER_AGENT, $options->userAgent);
        $this->assertNull($options->apiKey);
        $this->assertNull($options->accessToken);
    }

    #[Test]
    public function it_accepts_custom_values(): void
    {
        $options = new ClientOptions(
            apiKey: 'test-api-key',
            accessToken: 'test-token',
            baseUrl: 'https://custom.example.com',
            connectTimeout: 5.0,
            requestTimeout: 15.0,
        );

        $this->assertSame('test-api-key', $options->apiKey);
        $this->assertSame('test-token', $options->accessToken);
        $this->assertSame('https://custom.example.com', $options->baseUrl);
        $this->assertSame(5.0, $options->connectTimeout);
        $this->assertSame(15.0, $options->requestTimeout);
    }

    #[Test]
    public function it_derives_ws_url_from_base_url(): void
    {
        $options = new ClientOptions(baseUrl: 'https://api.example.com');

        $this->assertSame('wss://api.example.com', $options->wsUrl);
    }

    #[Test]
    public function it_uses_explicit_ws_url_when_provided(): void
    {
        $options = new ClientOptions(
            baseUrl: 'https://api.example.com',
            wsUrl: 'wss://custom-ws.example.com',
        );

        $this->assertSame('wss://custom-ws.example.com', $options->wsUrl);
    }

    #[Test]
    public function it_checks_api_key_presence(): void
    {
        $withKey = new ClientOptions(apiKey: 'test-key');
        $withEmpty = new ClientOptions(apiKey: '');
        $withNull = new ClientOptions(apiKey: null);

        $this->assertTrue($withKey->hasApiKey());
        $this->assertFalse($withEmpty->hasApiKey());
        $this->assertFalse($withNull->hasApiKey());
    }

    #[Test]
    public function it_checks_access_token_presence(): void
    {
        $withToken = new ClientOptions(accessToken: 'test-token');
        $withEmpty = new ClientOptions(accessToken: '');
        $withNull = new ClientOptions(accessToken: null);

        $this->assertTrue($withToken->hasAccessToken());
        $this->assertFalse($withEmpty->hasAccessToken());
        $this->assertFalse($withNull->hasAccessToken());
    }

    #[Test]
    public function it_returns_correct_auth_header_for_api_key(): void
    {
        $options = new ClientOptions(apiKey: 'test-api-key');

        $header = $options->getAuthHeader();

        // API keys are sent as Bearer token (parity with Node.js/Python SDKs)
        $this->assertSame('Authorization', $header['name']);
        $this->assertSame('Bearer test-api-key', $header['value']);
    }

    #[Test]
    public function it_returns_correct_auth_header_for_access_token(): void
    {
        $options = new ClientOptions(accessToken: 'test-token');

        $header = $options->getAuthHeader();

        $this->assertSame('Authorization', $header['name']);
        $this->assertSame('Bearer test-token', $header['value']);
    }

    #[Test]
    public function it_prefers_access_token_over_api_key(): void
    {
        $options = new ClientOptions(
            apiKey: 'test-api-key',
            accessToken: 'test-token',
        );

        $header = $options->getAuthHeader();

        $this->assertSame('Authorization', $header['name']);
        $this->assertSame('Bearer test-token', $header['value']);
    }

    #[Test]
    public function it_returns_null_auth_header_when_no_auth(): void
    {
        $options = new ClientOptions();

        $this->assertNull($options->getAuthHeader());
    }

    #[Test]
    public function it_creates_new_instance_with_updated_values(): void
    {
        $original = new ClientOptions(apiKey: 'original-key', baseUrl: 'https://original.com');
        $updated = $original->with(['apiKey' => 'new-key']);

        $this->assertSame('original-key', $original->apiKey);
        $this->assertSame('new-key', $updated->apiKey);
        $this->assertSame('https://original.com', $updated->baseUrl);
    }

    #[Test]
    public function it_includes_default_retry_config(): void
    {
        $options = new ClientOptions();

        $this->assertInstanceOf(RetryConfig::class, $options->retry);
        $this->assertSame(RetryConfig::DEFAULT_MAX_RETRIES, $options->retry->maxRetries);
    }

    #[Test]
    public function it_includes_default_circuit_breaker_config(): void
    {
        $options = new ClientOptions();

        $this->assertInstanceOf(CircuitBreakerConfig::class, $options->circuitBreaker);
        $this->assertTrue($options->circuitBreaker->enabled);
    }
}
