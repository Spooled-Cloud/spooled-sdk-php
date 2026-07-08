<?php

declare(strict_types=1);

namespace Spooled\Realtime;

use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * WebSocket client for real-time updates.
 *
 * Note: Requires ratchet/pawl for WebSocket support.
 * Install with: composer require ratchet/pawl
 */
final class WebSocketClient
{
    private readonly LoggerInterface $logger;

    private readonly string $wsUrl;

    private readonly ?string $apiKey;

    private readonly ?string $accessToken;

    private bool $running = false;

    private mixed $connection = null;

    private int $reconnectDelay = 1000;

    private int $maxReconnectDelay = 30000;

    private int $reconnectAttempts = 0;

    /** @var array<string, callable> */
    private array $eventHandlers = [];

    /** @var array<string> */
    private array $subscriptions = [];

    public function __construct(
        string $wsUrl,
        ?string $apiKey = null,
        ?string $accessToken = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->wsUrl = rtrim($wsUrl, '/');
        $this->apiKey = $apiKey;
        $this->accessToken = $accessToken;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Check if WebSocket support is available.
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Ratchet\Client\WebSocket::class);
    }

    /**
     * Subscribe to a topic.
     */
    public function subscribe(string $topic): self
    {
        $this->subscriptions[] = $topic;

        if ($this->connection !== null) {
            $this->sendSubscribe($topic);
        }

        return $this;
    }

    /**
     * Unsubscribe from a topic.
     */
    public function unsubscribe(string $topic): self
    {
        $this->subscriptions = array_filter(
            $this->subscriptions,
            fn (string $t) => $t !== $topic,
        );

        if ($this->connection !== null) {
            $this->sendUnsubscribe($topic);
        }

        return $this;
    }

    /**
     * Subscribe to job events.
     */
    public function subscribeToJob(string $jobId): self
    {
        return $this->subscribe("job:{$jobId}");
    }

    /**
     * Subscribe to queue events.
     */
    public function subscribeToQueue(string $queueName): self
    {
        return $this->subscribe("queue:{$queueName}");
    }

    /**
     * Register an event handler.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function on(string $event, callable $callback): self
    {
        $this->eventHandlers[$event] = $callback;

        return $this;
    }

    /**
     * Connect to the WebSocket server.
     */
    public function connect(): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                'WebSocket support requires ratchet/pawl. Install with: composer require ratchet/pawl',
            );
        }

        // The /api/v1/ws endpoint only authenticates a JWT in ?token=. A raw
        // API key is not accepted there; it must first be exchanged for an
        // access token via POST /api/v1/auth/login.
        if ($this->accessToken === null || $this->accessToken === '') {
            $hint = ($this->apiKey !== null && $this->apiKey !== '')
                ? ' An API key was provided, but the WebSocket endpoint only accepts a JWT;'
                    . ' exchange it for an access token via POST /api/v1/auth/login first.'
                : '';

            throw new RuntimeException(
                'WebSocket authentication requires a JWT access token (?token=).' . $hint,
            );
        }

        $this->running = true;

        // Establish the connection, then run the event loop exactly once.
        // Reconnection re-establishes the connection on the already-running
        // loop (see scheduleReconnect) and must NOT call run() again.
        $this->openConnection();

        \React\EventLoop\Loop::get()->run();
    }

    /**
     * Open a WebSocket connection on the shared React event loop without
     * running the loop. Safe to call from a reconnect timer.
     */
    private function openConnection(): void
    {
        $url = $this->buildUrl();
        $this->logger->info('Connecting to WebSocket', ['url' => $url]);

        // Use the shared React event loop and Ratchet client
        $loop = \React\EventLoop\Loop::get();
        $connector = new \Ratchet\Client\Connector($loop);

        $connector($url)->then(
            function (\Ratchet\Client\WebSocket $conn): void {
                $this->connection = $conn;
                $this->reconnectAttempts = 0;
                $this->emit('connected', []);

                // Subscribe to pending topics
                foreach ($this->subscriptions as $topic) {
                    $this->sendSubscribe($topic);
                }

                $conn->on('message', function (\Ratchet\RFC6455\Messaging\MessageInterface $msg): void {
                    $this->handleMessage((string) $msg);
                });

                $conn->on('close', function (int $code = null, string $reason = null): void {
                    $this->connection = null;
                    $this->emit('disconnected', ['code' => $code, 'reason' => $reason]);

                    if ($this->running) {
                        $this->scheduleReconnect();
                    }
                });
            },
            function (Throwable $e): void {
                $this->logger->error('WebSocket connection failed', ['error' => $e->getMessage()]);
                $this->emit('error', ['error' => $e]);

                if ($this->running) {
                    $this->scheduleReconnect();
                }
            },
        );
    }

    /**
     * Disconnect from the WebSocket server.
     */
    public function disconnect(): void
    {
        $this->running = false;

        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * Send a message.
     *
     * @param array<string, mixed> $data
     */
    public function send(array $data): void
    {
        if ($this->connection === null) {
            throw new RuntimeException('Not connected');
        }

        $this->connection->send(json_encode($data));
    }

    /**
     * Handle incoming message.
     */
    private function handleMessage(string $message): void
    {
        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('Invalid JSON message', ['error' => $e->getMessage()]);

            return;
        }

        $type = $data['type'] ?? 'message';
        $this->logger->debug('WebSocket message received', ['type' => $type]);

        // Dispatch to type-specific handlers
        if (isset($this->eventHandlers[$type])) {
            try {
                ($this->eventHandlers[$type])($data);
            } catch (Throwable $e) {
                $this->logger->warning('Event handler error', ['error' => $e->getMessage()]);
            }
        }

        // Dispatch to generic message handler
        if (isset($this->eventHandlers['message'])) {
            try {
                ($this->eventHandlers['message'])($data);
            } catch (Throwable $e) {
                $this->logger->warning('Message handler error', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Send subscribe message.
     */
    private function sendSubscribe(string $topic): void
    {
        $this->send([
            'action' => 'subscribe',
            'topic' => $topic,
        ]);
    }

    /**
     * Send unsubscribe message.
     */
    private function sendUnsubscribe(string $topic): void
    {
        $this->send([
            'action' => 'unsubscribe',
            'topic' => $topic,
        ]);
    }

    /**
     * Schedule a reconnection attempt.
     */
    private function scheduleReconnect(): void
    {
        $this->reconnectAttempts++;
        $delay = min(
            $this->reconnectDelay * (2 ** ($this->reconnectAttempts - 1)),
            $this->maxReconnectDelay,
        );

        $this->logger->info('Reconnecting in {delay}ms', ['delay' => $delay]);
        $this->emit('reconnecting', ['delay' => $delay, 'attempt' => $this->reconnectAttempts]);

        $loop = \React\EventLoop\Loop::get();
        $loop->addTimer($delay / 1000, function (): void {
            if ($this->running) {
                // Re-establish on the already-running loop. Do NOT call
                // connect() here: that would invoke Loop::run() a second time
                // while the outer run() is still on the stack.
                $this->openConnection();
            }
        });
    }

    /**
     * Build the WebSocket URL with authentication.
     */
    private function buildUrl(): string
    {
        $url = $this->wsUrl . '/api/v1/ws';

        // The backend /ws endpoint authenticates only a JWT in ?token=.
        // connect() guarantees an access token is present; there is no
        // api_key fallback because the endpoint does not accept it.
        if ($this->accessToken !== null && $this->accessToken !== '') {
            $url .= '?' . http_build_query(['token' => $this->accessToken]);
        }

        return $url;
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
