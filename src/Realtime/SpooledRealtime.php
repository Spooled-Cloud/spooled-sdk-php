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

    public function __construct(
        ClientOptions $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->options = $options;
        $this->logger = $logger;
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
