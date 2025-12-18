<?php

declare(strict_types=1);

namespace Spooled;

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
     */
    public function realtime(): SpooledRealtime
    {
        if ($this->realtimeClient !== null) {
            return $this->realtimeClient;
        }

        $options = $this->options;

        // If we don't have a JWT yet but we have an API key, exchange it for JWT (Node/Python behavior).
        $accessToken = $this->httpClient->getAccessToken();
        if (($accessToken === null || $accessToken === '') && $options->apiKey !== null && $options->apiKey !== '') {
            $login = $this->auth->login($options->apiKey);
            $this->setAccessToken($login->accessToken);
            $this->setRefreshToken($login->refreshToken);

            $options = $options->with([
                'accessToken' => $login->accessToken,
                'refreshToken' => $login->refreshToken,
            ]);
        } elseif ($accessToken !== null && $accessToken !== '') {
            $options = $options->with(['accessToken' => $accessToken]);
        }

        $this->realtimeClient = new SpooledRealtime($options, $this->logger);

        return $this->realtimeClient;
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
