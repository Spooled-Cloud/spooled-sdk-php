<?php

declare(strict_types=1);

namespace Spooled;

use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Spooled\Config\ClientOptions;
use Spooled\Grpc\GrpcOptions;
use Spooled\Grpc\SpooledGrpcClient;
use Spooled\Http\HttpClient;
use Spooled\Realtime\SpooledRealtime;
use Spooled\Resources\AdminResource;
use Spooled\Resources\ApiKeysResource;
use Spooled\Resources\AuthResource;
use Spooled\Resources\BillingResource;
use Spooled\Resources\DashboardResource;
use Spooled\Resources\HealthResource;
use Spooled\Resources\JobsResource;
use Spooled\Resources\MetricsResource;
use Spooled\Resources\OrganizationsResource;
use Spooled\Resources\QueuesResource;
use Spooled\Resources\SchedulesResource;
use Spooled\Resources\WebhookIngestionResource;
use Spooled\Resources\WebhooksResource;
use Spooled\Resources\WorkersResource;
use Spooled\Resources\WorkflowsResource;

/**
 * Spooled Cloud SDK client.
 *
 * Main entry point for interacting with the Spooled Cloud API.
 *
 * @example
 * ```php
 * $client = new SpooledClient(new ClientOptions(
 *     apiKey: 'sk_test_...',
 *     baseUrl: 'https://api.spooled.cloud',
 * ));
 *
 * // Create a job
 * $job = $client->jobs->create([
 *     'queue' => 'my-queue',
 *     'payload' => ['data' => 'value'],
 * ]);
 * ```
 */
final class SpooledClient
{
    /**
     * Refresh the realtime JWT this many seconds before it actually expires, so
     * a token is never used right at the edge of its validity window.
     */
    private const TOKEN_EXPIRY_LEEWAY_SECONDS = 60;

    /** Jobs resource */
    public readonly JobsResource $jobs;

    /** Queues resource */
    public readonly QueuesResource $queues;

    /** Workers resource */
    public readonly WorkersResource $workers;

    /** Schedules resource */
    public readonly SchedulesResource $schedules;

    /** Workflows resource */
    public readonly WorkflowsResource $workflows;

    /** Webhooks resource */
    public readonly WebhooksResource $webhooks;

    /** API keys resource */
    public readonly ApiKeysResource $apiKeys;

    /** Organizations resource */
    public readonly OrganizationsResource $organizations;

    /** Admin resource */
    public readonly AdminResource $admin;

    /** Auth resource */
    public readonly AuthResource $auth;

    /** Dashboard resource */
    public readonly DashboardResource $dashboard;

    /** Health resource */
    public readonly HealthResource $health;

    /** Metrics resource */
    public readonly MetricsResource $metrics;

    /** Webhook ingestion resource */
    public readonly WebhookIngestionResource $ingest;

    /** Billing resource */
    public readonly BillingResource $billing;

    private readonly HttpClient $httpClient;

    private readonly ClientOptions $options;

    private readonly LoggerInterface $logger;

    private ?SpooledGrpcClient $grpcClient = null;

    private ?SpooledRealtime $realtimeClient = null;

    /**
     * Cached JWT obtained by exchanging the API key for realtime auth.
     *
     * Reused across realtime() calls and reconnects until it nears expiry so a
     * reconnect storm does not hammer POST /api/v1/auth/login (rate limited, 429).
     */
    private ?string $cachedRealtimeToken = null;

    /**
     * Unix timestamp (seconds) at which the cached realtime JWT expires, or null
     * when it could not be determined.
     */
    private ?int $cachedRealtimeTokenExpiresAt = null;

    public function __construct(ClientOptions $options)
    {
        $this->options = $options;
        $this->logger = $options->logger ?? new NullLogger();
        $this->httpClient = new HttpClient($options, $this->logger);

        // Initialize resources
        $this->jobs = new JobsResource($this->httpClient);
        $this->queues = new QueuesResource($this->httpClient);
        $this->workers = new WorkersResource($this->httpClient);
        $this->schedules = new SchedulesResource($this->httpClient);
        $this->workflows = new WorkflowsResource($this->httpClient);
        $this->webhooks = new WebhooksResource($this->httpClient);
        $this->apiKeys = new ApiKeysResource($this->httpClient);
        $this->organizations = new OrganizationsResource($this->httpClient);
        $this->admin = new AdminResource($this->httpClient, $options->adminKey);
        $this->auth = new AuthResource($this->httpClient);
        $this->dashboard = new DashboardResource($this->httpClient);
        $this->health = new HealthResource($this->httpClient);
        $this->metrics = new MetricsResource($this->httpClient);
        $this->ingest = new WebhookIngestionResource($this->httpClient);
        $this->billing = new BillingResource($this->httpClient);
    }

    /**
     * Get the underlying HTTP client.
     */
    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }

    /**
     * Get the client options/config.
     */
    public function getOptions(): ClientOptions
    {
        return $this->options;
    }

    /**
     * Get the client configuration (alias for getOptions).
     *
     * This matches the Node.js SDK's getConfig() method.
     */
    public function getConfig(): ClientOptions
    {
        return $this->options;
    }

    /**
     * Get the logger instance.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Update the access token (for token refresh flows).
     */
    public function setAccessToken(string $token): void
    {
        $this->httpClient->setAccessToken($token);
    }

    /**
     * Update the refresh token.
     */
    public function setRefreshToken(string $token): void
    {
        $this->httpClient->setRefreshToken($token);
    }

    /**
     * Reset the circuit breaker to closed state.
     *
     * Useful when you know the service has recovered and want to immediately
     * resume normal operation without waiting for the timeout.
     */
    public function resetCircuitBreaker(): void
    {
        $this->httpClient->getCircuitBreaker()->reset();
    }

    /**
     * Get circuit breaker stats (parity with Node/Python).
     *
     * @return array{state: string, failureCount: int, successCount: int, openedAt: float|null, timeout: float}
     */
    public function getCircuitBreakerStats(): array
    {
        $cb = $this->httpClient->getCircuitBreaker();

        return [
            'state' => $cb->getState(),
            'failureCount' => $cb->getFailureCount(),
            'successCount' => $cb->getSuccessCount(),
            'openedAt' => $cb->getOpenedAt(),
            'timeout' => $this->options->circuitBreaker->timeout,
        ];
    }

    /**
     * Lazily create a gRPC client (parity with Node/Python `client.grpc`).
     *
     * Note: gRPC requires an API key.
     */
    public function grpc(?GrpcOptions $options = null): SpooledGrpcClient
    {
        if ($this->grpcClient !== null) {
            return $this->grpcClient;
        }

        $apiKey = $this->options->apiKey;
        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException('gRPC client requires an API key (set ClientOptions::$apiKey)');
        }

        $address = $this->options->grpcAddress ?? 'grpc.spooled.cloud:443';

        $this->grpcClient = new SpooledGrpcClient(
            $options ?? new GrpcOptions(address: $address, apiKey: $apiKey),
            $this->logger,
        );
        // Ensure subresources are ready for immediate use like $client->grpc()->queue->enqueue(...)
        $this->grpcClient->waitForReady();

        return $this->grpcClient;
    }

    /**
     * Lazily create unified realtime client (parity with Node/Python realtime helper).
     *
     * If no access token is set but an API key is available, we will exchange API key for JWT.
     * The exchanged JWT is cached on this client and reused across realtime() calls and
     * reconnects until it nears expiry, so reconnect storms do not hammer POST /auth/login.
     */
    public function realtime(): SpooledRealtime
    {
        if ($this->realtimeClient !== null) {
            return $this->realtimeClient;
        }

        $accessToken = $this->resolveRealtimeAccessToken();

        $options = $accessToken !== null && $accessToken !== ''
            ? $this->options->with(['accessToken' => $accessToken])
            : $this->options;

        $this->realtimeClient = new SpooledRealtime($options, $this->logger);

        return $this->realtimeClient;
    }

    /**
     * Determine the access token to use for the realtime data plane.
     *
     * Preference order:
     *  1. An access token configured on the client is caller-owned and used verbatim.
     *  2. A token already present on the HTTP client that we did not exchange ourselves
     *     (e.g. set via setAccessToken()) is likewise treated as caller-owned.
     *  3. Otherwise, if an API key is configured, exchange it for a (cached) JWT.
     */
    private function resolveRealtimeAccessToken(): ?string
    {
        $options = $this->options;

        if ($options->accessToken !== null && $options->accessToken !== '') {
            return $options->accessToken;
        }

        $current = $this->httpClient->getAccessToken();
        if ($current !== null && $current !== '' && $current !== $this->cachedRealtimeToken) {
            return $current;
        }

        if ($options->apiKey !== null && $options->apiKey !== '') {
            return $this->exchangeApiKeyForToken($options->apiKey);
        }

        return $current;
    }

    /**
     * Exchange the API key for a realtime JWT, reusing the cached JWT until it
     * approaches expiry.
     *
     * The JWT `exp` claim is read by base64-decoding the payload segment without
     * verifying the signature; the token is considered expired
     * self::TOKEN_EXPIRY_LEEWAY_SECONDS early. A fresh login is performed only
     * when no JWT is cached or the cached one is at/near expiry.
     */
    private function exchangeApiKeyForToken(string $apiKey): string
    {
        if (
            $this->cachedRealtimeToken !== null
            && $this->cachedRealtimeTokenExpiresAt !== null
            && $this->cachedRealtimeTokenExpiresAt - self::TOKEN_EXPIRY_LEEWAY_SECONDS > time()
        ) {
            return $this->cachedRealtimeToken;
        }

        $tokens = $this->auth->login($apiKey);

        $this->cachedRealtimeToken = $tokens->accessToken;
        $this->cachedRealtimeTokenExpiresAt = $this->decodeJwtExpiry($tokens->accessToken)
            ?? ($tokens->expiresIn !== null ? time() + $tokens->expiresIn : null);

        $this->setAccessToken($tokens->accessToken);
        if ($tokens->refreshToken !== null && $tokens->refreshToken !== '') {
            $this->setRefreshToken($tokens->refreshToken);
        }

        return $tokens->accessToken;
    }

    /**
     * Read the `exp` claim (unix seconds) from a JWT without verifying its signature.
     *
     * Returns null when the token is not a decodable JWT or has no numeric `exp`.
     */
    private function decodeJwtExpiry(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }

        $payload = $this->base64UrlDecode($parts[1]);
        if ($payload === null) {
            return null;
        }

        try {
            /** @var mixed $claims */
            $claims = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($claims) || !isset($claims['exp']) || !is_numeric($claims['exp'])) {
            return null;
        }

        return (int) $claims['exp'];
    }

    /**
     * Decode a base64url segment (JWT parts use base64url without padding).
     */
    private function base64UrlDecode(string $segment): ?string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Close/cleanup any optional clients (gRPC / realtime).
     */
    public function close(): void
    {
        $this->realtimeClient?->stop();
        $this->realtimeClient = null;

        $this->grpcClient?->close();
        $this->grpcClient = null;
    }
}
