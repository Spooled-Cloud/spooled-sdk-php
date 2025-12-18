<?php

declare(strict_types=1);

namespace Spooled\Config;

use Psr\Log\LoggerInterface;

/**
 * Client configuration options.
 */
final readonly class ClientOptions
{
    public const DEFAULT_BASE_URL = 'https://api.spooled.cloud';

    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    public const DEFAULT_REQUEST_TIMEOUT = 30.0;

    public const DEFAULT_USER_AGENT = 'spooled-php/1.0.0';

    public string $baseUrl;

    public ?string $wsUrl;

    public ?string $grpcAddress;

    public float $connectTimeout;

    public float $requestTimeout;

    public RetryConfig $retry;

    public CircuitBreakerConfig $circuitBreaker;

    public string $userAgent;

    /** @var array<string, string> */
    public array $headers;

    public function __construct(
        public ?string $apiKey = null,
        public ?string $accessToken = null,
        public ?string $refreshToken = null,
        public ?string $adminKey = null,
        ?string $baseUrl = null,
        ?string $wsUrl = null,
        ?string $grpcAddress = null,
        ?float $connectTimeout = null,
        ?float $requestTimeout = null,
        ?RetryConfig $retry = null,
        ?CircuitBreakerConfig $circuitBreaker = null,
        ?string $userAgent = null,
        /** @var array<string, string> */
        array $headers = [],
        public ?LoggerInterface $logger = null,
    ) {
        $this->baseUrl = $baseUrl ?? self::DEFAULT_BASE_URL;
        $this->wsUrl = $wsUrl ?? $this->deriveWsUrl($this->baseUrl);
        $this->grpcAddress = $grpcAddress;
        $this->connectTimeout = $connectTimeout ?? self::DEFAULT_CONNECT_TIMEOUT;
        $this->requestTimeout = $requestTimeout ?? self::DEFAULT_REQUEST_TIMEOUT;
        $this->retry = $retry ?? new RetryConfig();
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreakerConfig();
        $this->userAgent = $userAgent ?? self::DEFAULT_USER_AGENT;
        $this->headers = $headers;
    }

    /**
     * Derive WebSocket URL from base URL.
     */
    private function deriveWsUrl(string $baseUrl): string
    {
        return preg_replace('/^http/', 'ws', $baseUrl) ?? $baseUrl;
    }

    /**
     * Check if API key authentication is configured.
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Check if access token authentication is configured.
     */
    public function hasAccessToken(): bool
    {
        return $this->accessToken !== null && $this->accessToken !== '';
    }

    /**
     * Check if admin key is configured.
     */
    public function hasAdminKey(): bool
    {
        return $this->adminKey !== null && $this->adminKey !== '';
    }

    /**
     * Get the primary authentication header value.
     *
     * @return array{name: string, value: string}|null
     */
    public function getAuthHeader(): ?array
    {
        if ($this->hasAccessToken()) {
            return [
                'name' => 'Authorization',
                'value' => 'Bearer ' . $this->accessToken,
            ];
        }

        if ($this->hasApiKey()) {
            /** @phpstan-ignore-next-line hasApiKey() guarantees apiKey is not null */
            return [
                // Node/Python send API keys as Bearer tokens; backend accepts this.
                'name' => 'Authorization',
                'value' => 'Bearer ' . (string) $this->apiKey,
            ];
        }

        return null;
    }

    /**
     * Create a new instance with updated values.
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        return new self(
            apiKey: $values['apiKey'] ?? $this->apiKey,
            accessToken: $values['accessToken'] ?? $this->accessToken,
            refreshToken: $values['refreshToken'] ?? $this->refreshToken,
            adminKey: $values['adminKey'] ?? $this->adminKey,
            baseUrl: $values['baseUrl'] ?? $this->baseUrl,
            wsUrl: $values['wsUrl'] ?? $this->wsUrl,
            grpcAddress: $values['grpcAddress'] ?? $this->grpcAddress,
            connectTimeout: $values['connectTimeout'] ?? $this->connectTimeout,
            requestTimeout: $values['requestTimeout'] ?? $this->requestTimeout,
            retry: $values['retry'] ?? $this->retry,
            circuitBreaker: $values['circuitBreaker'] ?? $this->circuitBreaker,
            userAgent: $values['userAgent'] ?? $this->userAgent,
            headers: $values['headers'] ?? $this->headers,
            logger: $values['logger'] ?? $this->logger,
        );
    }
}
