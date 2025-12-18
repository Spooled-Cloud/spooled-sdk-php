<?php

declare(strict_types=1);

namespace Spooled\Realtime;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Server-Sent Events (SSE) client for real-time updates.
 */
final class SseClient
{
    private readonly GuzzleClient $guzzle;

    private readonly LoggerInterface $logger;

    private readonly string $baseUrl;

    private readonly ?string $apiKey;

    private readonly ?string $accessToken;

    private bool $running = false;

    private int $reconnectDelay = 1000; // ms

    private int $maxReconnectDelay = 30000; // ms

    private int $reconnectAttempts = 0;

    /** @var array<string, callable> */
    private array $eventHandlers = [];

    /** @var array<string, callable> */
    private array $subscriptions = [];

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?string $accessToken = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->accessToken = $accessToken;
        $this->logger = $logger ?? new NullLogger();

        $this->guzzle = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            RequestOptions::STREAM => true,
            RequestOptions::READ_TIMEOUT => 0, // No read timeout for streaming
            RequestOptions::HEADERS => [
                'Accept' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ],
        ]);
    }

    /**
     * Subscribe to all events.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function subscribe(callable $callback): self
    {
        $this->subscriptions['*'] = $callback;

        return $this;
    }

    /**
     * Subscribe to events for a specific job.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function subscribeToJob(string $jobId, callable $callback): self
    {
        $this->subscriptions["job:{$jobId}"] = $callback;

        return $this;
    }

    /**
     * Subscribe to events for a specific queue.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function subscribeToQueue(string $queueName, callable $callback): self
    {
        $this->subscriptions["queue:{$queueName}"] = $callback;

        return $this;
    }

    /**
     * Register an event handler.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function on(string $eventType, callable $callback): self
    {
        $this->eventHandlers[$eventType] = $callback;

        return $this;
    }

    /**
     * Start listening for events (blocking).
     */
    public function listen(): void
    {
        $this->running = true;

        while ($this->running) {
            try {
                $this->connect();
            } catch (Throwable $e) {
                $this->logger->error('SSE connection error', ['error' => $e->getMessage()]);

                // Check if stop() was called during connection attempt
                // @phpstan-ignore-next-line Running flag can be changed by stop()
                if ($this->running === false) {
                    break;
                }

                $this->reconnect();
            }
        }
    }

    /**
     * Stop listening.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Connect and process events.
     */
    private function connect(): void
    {
        $url = $this->buildUrl();
        $headers = $this->buildHeaders();

        $this->logger->info('Connecting to SSE endpoint', ['url' => $url]);

        $response = $this->guzzle->request('GET', $url, [
            RequestOptions::HEADERS => $headers,
            RequestOptions::STREAM => true,
        ]);

        $body = $response->getBody();
        $this->reconnectAttempts = 0; // Reset on successful connection

        $this->emit('connected', []);

        $buffer = '';

        while ($this->running && !$body->eof()) {
            $chunk = $body->read(1024);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // Process complete events
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $eventData = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $this->processEvent($eventData);
            }
        }
    }

    /**
     * Process a single SSE event.
     */
    private function processEvent(string $eventData): void
    {
        $event = $this->parseEvent($eventData);

        if ($event === null) {
            return;
        }

        $this->logger->debug('SSE event received', [
            'type' => $event['type'],
            'id' => $event['id'] ?? null,
        ]);

        // Dispatch to type-specific handlers
        if (isset($this->eventHandlers[$event['type']])) {
            try {
                ($this->eventHandlers[$event['type']])($event);
            } catch (Throwable $e) {
                $this->logger->warning('Event handler error', ['error' => $e->getMessage()]);
            }
        }

        // Dispatch to subscriptions
        $this->dispatchToSubscriptions($event);
    }

    /**
     * Parse SSE event data.
     *
     * @return array<string, mixed>|null
     */
    private function parseEvent(string $eventData): ?array
    {
        $lines = explode("\n", $eventData);
        $event = [
            'type' => 'message',
            'data' => '',
            'id' => null,
            'retry' => null,
        ];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            $colonPos = strpos($line, ':');

            if ($colonPos === false) {
                continue;
            }

            $field = substr($line, 0, $colonPos);
            $value = ltrim(substr($line, $colonPos + 1), ' ');

            switch ($field) {
                case 'event':
                    $event['type'] = $value;
                    break;
                case 'data':
                    $event['data'] .= ($event['data'] !== '' ? "\n" : '') . $value;
                    break;
                case 'id':
                    $event['id'] = $value;
                    break;
                case 'retry':
                    $event['retry'] = (int) $value;
                    if ($event['retry'] > 0) {
                        $this->reconnectDelay = $event['retry'];
                    }
                    break;
            }
        }

        if ($event['data'] === '') {
            return null;
        }

        // Try to parse JSON data
        try {
            $parsed = json_decode($event['data'], true, 512, JSON_THROW_ON_ERROR);
            $event['data'] = $parsed;
        } catch (JsonException) {
            // Keep as string
        }

        return $event;
    }

    /**
     * Dispatch event to matching subscriptions.
     *
     * @param array<string, mixed> $event
     */
    private function dispatchToSubscriptions(array $event): void
    {
        // Global subscription
        if (isset($this->subscriptions['*'])) {
            try {
                ($this->subscriptions['*'])($event);
            } catch (Throwable $e) {
                $this->logger->warning('Subscription callback error', ['error' => $e->getMessage()]);
            }
        }

        // Check for job/queue specific subscriptions
        $data = $event['data'] ?? [];

        if (is_array($data)) {
            $jobId = $data['jobId'] ?? $data['job_id'] ?? null;
            $queueName = $data['queueName'] ?? $data['queue_name'] ?? $data['queue'] ?? null;

            if ($jobId !== null && isset($this->subscriptions["job:{$jobId}"])) {
                try {
                    ($this->subscriptions["job:{$jobId}"])($event);
                } catch (Throwable $e) {
                    $this->logger->warning('Job subscription callback error', ['error' => $e->getMessage()]);
                }
            }

            if ($queueName !== null && isset($this->subscriptions["queue:{$queueName}"])) {
                try {
                    ($this->subscriptions["queue:{$queueName}"])($event);
                } catch (Throwable $e) {
                    $this->logger->warning('Queue subscription callback error', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * Handle reconnection with backoff.
     */
    private function reconnect(): void
    {
        $this->reconnectAttempts++;
        $delay = min(
            $this->reconnectDelay * (2 ** ($this->reconnectAttempts - 1)),
            $this->maxReconnectDelay,
        );

        $this->logger->info('Reconnecting in {delay}ms', ['delay' => $delay]);
        $this->emit('reconnecting', ['delay' => $delay, 'attempt' => $this->reconnectAttempts]);

        usleep($delay * 1000);
    }

    /**
     * Build the SSE endpoint URL.
     */
    private function buildUrl(): string
    {
        // Default to general events endpoint
        return '/api/v1/events';
    }

    /**
     * Build request headers.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ];

        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        } elseif ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * Emit an internal event.
     *
     * @param array<string, mixed> $data
     */
    private function emit(string $event, array $data): void
    {
        if (isset($this->eventHandlers[$event])) {
            try {
                ($this->eventHandlers[$event])($data);
            } catch (Throwable $e) {
                $this->logger->warning('Event handler error', ['event' => $event, 'error' => $e->getMessage()]);
            }
        }
    }
}
