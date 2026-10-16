<?php

declare(strict_types=1);

namespace Spooled\Realtime;

use Psr\Log\LoggerInterface;
use Spooled\Config\ClientOptions;

/**
 * Unified realtime client that supports both SSE and WebSocket.
 */
final class SpooledRealtime
{
    private readonly ClientOptions $options;

    private readonly ?LoggerInterface $logger;

    private ?SseClient $sseClient = null;

    private ?WebSocketClient $wsClient = null;

    /**
     * Called on every WebSocket (re)connect. The optional bool is true after
     * close 1008, asking the provider to drop a cached JWT and mint another.
     *
     * @var callable|null
     */
    private readonly mixed $wsTokenProvider;

    /** @var callable(): ?string|null */
    private readonly mixed $accessTokenProvider;

    /**
     * @param callable|null $wsTokenProvider Returns a currently valid JWT for the
     *                                       WebSocket endpoint (which accepts no API key). Called on every
     *                                       (re)connect. When it accepts a bool, `true` means drop any cached
     *                                       JWT and log in again (close 1008).
     * @param callable(): ?string|null $accessTokenProvider Returns the caller-owned JWT for
     *                                                      SSE, or null to authenticate with the API key. Called on every (re)connect.
     */
    public function __construct(
        ClientOptions $options,
        ?LoggerInterface $logger = null,
        ?callable $wsTokenProvider = null,
        ?callable $accessTokenProvider = null,
    ) {
        $this->options = $options;
        $this->logger = $logger;
        $this->wsTokenProvider = $wsTokenProvider;
        $this->accessTokenProvider = $accessTokenProvider;
    }

    /**
     * Get SSE client (lazy initialized).
     */
    public function sse(): SseClient
    {
        if ($this->sseClient === null) {
            $this->sseClient = new SseClient(
                baseUrl: $this->options->baseUrl,
                apiKey: $this->options->apiKey,
                accessToken: $this->options->accessToken,
                logger: $this->logger,
                tokenProvider: $this->accessTokenProvider,
            );
        }

        return $this->sseClient;
    }

    /**
     * Get WebSocket client (lazy initialized).
     */
    public function ws(): WebSocketClient
    {
        if ($this->wsClient === null) {
            $this->wsClient = new WebSocketClient(
                wsUrl: $this->options->wsUrl ?? $this->deriveWsUrl($this->options->baseUrl),
                apiKey: $this->options->apiKey,
                accessToken: $this->options->accessToken,
                logger: $this->logger,
                tokenProvider: $this->wsTokenProvider,
            );
        }

        return $this->wsClient;
    }

    /**
     * Check if WebSocket support is available.
     */
    public function isWebSocketAvailable(): bool
    {
        return WebSocketClient::isAvailable();
    }

    /**
     * Create a unified subscription that uses the best available transport.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function subscribe(callable $callback): void
    {
        // Prefer WebSocket if available, fall back to SSE
        if ($this->isWebSocketAvailable()) {
            $this->ws()
                ->on('message', $callback)
                ->connect();
        } else {
            $this->sse()
                ->subscribe($callback)
                ->listen();
        }
    }

    /**
     * Subscribe to job events.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function subscribeToJob(string $jobId, callable $callback): void
    {
        if ($this->isWebSocketAvailable()) {
            $this->ws()
                ->subscribeToJob($jobId)
                ->on('message', $callback)
                ->connect();
        } else {
            $this->sse()
                ->subscribeToJob($jobId, $callback)
                ->listen();
        }
    }

    /**
     * Subscribe to queue events.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function subscribeToQueue(string $queueName, callable $callback): void
    {
        if ($this->isWebSocketAvailable()) {
            $this->ws()
                ->subscribeToQueue($queueName)
                ->on('message', $callback)
                ->connect();
        } else {
            $this->sse()
                ->subscribeToQueue($queueName, $callback)
                ->listen();
        }
    }

    /**
     * Stop all realtime connections.
     */
    public function stop(): void
    {
        $this->sseClient?->stop();
        $this->wsClient?->disconnect();
    }

    /**
     * Derive WebSocket URL from HTTP URL.
     */
    private function deriveWsUrl(string $httpUrl): string
    {
        return preg_replace('/^http/', 'ws', $httpUrl) ?? $httpUrl;
    }
}
