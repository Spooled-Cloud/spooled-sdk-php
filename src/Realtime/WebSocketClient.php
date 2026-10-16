<?php

declare(strict_types=1);

namespace Spooled\Realtime;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionFunction;
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
    /**
     * Maps the backend's PascalCase event `type` (the RealtimeEvent enum
     * variant name serialized by the server) to the SDK's dotted event names
     * used when registering typed handlers via on(). See the backend
     * RealtimeEvent enum in src/api/handlers/realtime.rs for the full set.
     *
     * @var array<string, string>
     */
    private const EVENT_TYPE_MAP = [
        'JobStatusChange' => 'job.status_changed',
        'JobCreated' => 'job.created',
        'JobCompleted' => 'job.completed',
        'JobFailed' => 'job.failed',
        'QueueStats' => 'queue.stats',
        'WorkerHeartbeat' => 'worker.heartbeat',
        'WorkerRegistered' => 'worker.registered',
        'WorkerDeregistered' => 'worker.deregistered',
        'SystemHealth' => 'system.health',
        'Ping' => 'ping',
        'Error' => 'error',
    ];

    /**
     * A connection that lived this long (seconds) counts as healthy: only then
     * is the reconnect backoff reset. Resetting on every successful open meant
     * a server that accepts and immediately closes was retried for as long as
     * the process ran.
     */
    private const STABLE_CONNECTION_SECONDS = 30.0;

    /**
     * Connections in a row that opened and then closed before
     * {@see self::STABLE_CONNECTION_SECONDS} after which the client stops
     * dialing. A server that accepts and then closes (close 1011, the event
     * channel could not be opened) would otherwise loop forever. Connect
     * failures (the server is down) do not count: an outage of any length
     * keeps backing off and reconnects when the server is back.
     */
    private const MAX_RECONNECT_ATTEMPTS = 10;

    /**
     * WebSocket close code 1008 (policy violation). The server uses it when the
     * access token expired or authorization was revoked, and it means that
     * token must not be presented again.
     */
    private const CLOSE_DO_NOT_REUSE_TOKEN = 1008;

    private readonly LoggerInterface $logger;

    private readonly string $wsUrl;

    private readonly ?string $apiKey;

    private readonly ?string $accessToken;

    /**
     * Returns a currently valid JWT, or null. Asked again on every (re)connect so
     * a long-lived connection loop never presents an expired token; the caller
     * decides how the token is minted and cached.
     *
     * Close 1008 calls it with `true` when the callable accepts an argument.
     * That asks the caller to drop a cached JWT and log in with the API key:
     * a session can be revoked while the cached token is still inside its
     * lifetime, and treating the repeated string as terminal would end
     * auto-reconnect until the cache expired. The fresh token is presented
     * once. A provider that hands back a token the server already rejected, or
     * a second close 1008 for that fresh token before the socket has stayed
     * up, stops the loop. Zero-argument providers are called with no argument.
     *
     * @var callable|null
     */
    private readonly mixed $tokenProvider;

    private bool $running = false;

    private mixed $connection = null;

    private int $reconnectDelay = 1000;

    private int $maxReconnectDelay = 30000;

    private int $reconnectAttempts = 0;

    /** Connections in a row that opened and then closed before they were stable. */
    private int $unstableCloses = 0;

    /** When the current socket opened. Null while disconnected. */
    private ?float $connectedAt = null;

    /**
     * The access token actually placed on the last connection URL. Close 1008
     * rejects this value, not whichever token happens to be configured now.
     */
    private ?string $presentedToken = null;

    /**
     * Tokens the server rejected with close 1008. Each is presented at most
     * once; a forced refresh may supply a replacement, which is itself
     * presented once.
     *
     * @var array<string, true>
     */
    private array $rejectedTokens = [];

    /**
     * Set by close 1008 so the next resolve asks the provider to mint a new
     * JWT instead of returning its cached one.
     */
    private bool $forceTokenRefresh = false;

    /**
     * The token presented after that forced refresh. A close 1008 for this
     * same value, before the socket has stayed up, means the fresh token was
     * rejected too. Cleared once a connection proves stable, so a later
     * revocation can refresh again.
     */
    private ?string $refreshedToken = null;

    /** @var array<string, callable> */
    private array $eventHandlers = [];

    /** @var list<array{queue: ?string, jobId: ?string}> */
    private array $subscriptions = [];

    public function __construct(
        string $wsUrl,
        ?string $apiKey = null,
        ?string $accessToken = null,
        ?LoggerInterface $logger = null,
        ?callable $tokenProvider = null,
    ) {
        $this->wsUrl = rtrim($wsUrl, '/');
        $this->apiKey = $apiKey;
        $this->accessToken = $accessToken;
        $this->logger = $logger ?? new NullLogger();
        $this->tokenProvider = $tokenProvider;
    }

    /**
     * Check if WebSocket support is available.
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Ratchet\Client\WebSocket::class);
    }

    /**
     * Subscribe to updates for a queue and/or a specific job.
     *
     * Sends the backend's subscribe command ({cmd: 'subscribe', queue, job_id}).
     * The server does not send a subscribe acknowledgement, so this does not
     * block waiting for one.
     */
    public function subscribe(?string $queue = null, ?string $jobId = null): self
    {
        $this->subscriptions[] = ['queue' => $queue, 'jobId' => $jobId];

        if ($this->connection !== null) {
            $this->sendSubscribe($queue, $jobId);
        }

        return $this;
    }

    /**
     * Unsubscribe from updates for a queue and/or a specific job.
     */
    public function unsubscribe(?string $queue = null, ?string $jobId = null): self
    {
        $this->subscriptions = array_values(array_filter(
            $this->subscriptions,
            fn (array $sub): bool => !($sub['queue'] === $queue && $sub['jobId'] === $jobId),
        ));

        if ($this->connection !== null) {
            $this->sendUnsubscribe($queue, $jobId);
        }

        return $this;
    }

    /**
     * Subscribe to job events.
     */
    public function subscribeToJob(string $jobId): self
    {
        return $this->subscribe(jobId: $jobId);
    }

    /**
     * Subscribe to queue events.
     */
    public function subscribeToQueue(string $queueName): self
    {
        return $this->subscribe(queue: $queueName);
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
        if ($this->resolveAccessToken() === null) {
            if ($this->rejectedTokens !== []) {
                throw new RuntimeException(
                    'WebSocket authorization failed (close 1008). The rejected access token will not be reused.',
                );
            }

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
        try {
            $url = $this->buildUrl();
        } catch (Throwable $e) {
            // Minting a fresh token failed (network, rate limit): retry later
            // rather than letting the exception unwind the running event loop.
            $this->logger->error('WebSocket token unavailable', ['error' => $e->getMessage()]);
            $this->emit('error', ['error' => $e]);

            if ($this->running) {
                $this->scheduleReconnect();
            }

            return;
        }

        // No token to dial. After close 1008 this means the forced refresh
        // handed back a token the server already rejected (or there was no
        // provider to mint one). Stop instead of reconnecting with nothing.
        if ($this->presentedToken === null) {
            $this->running = false;
            $message = $this->rejectedTokens !== []
                ? 'WebSocket closed with 1008 (token expired or authorization revoked). The rejected access token will not be reused.'
                : 'WebSocket authentication requires a JWT access token (?token=).';
            $this->logger->error($message);
            $this->emit('error', ['error' => new RuntimeException($message)]);

            return;
        }

        $this->logger->info('Connecting to WebSocket');

        // Use the shared React event loop and Ratchet client
        $loop = \React\EventLoop\Loop::get();
        $connector = new \Ratchet\Client\Connector($loop);

        $connector($url)->then(
            function (\Ratchet\Client\WebSocket $conn): void {
                $this->connection = $conn;
                $this->noteConnected();
                $this->emit('connected', []);

                // Replay pending subscriptions on (re)connect.
                foreach ($this->subscriptions as $sub) {
                    $this->sendSubscribe($sub['queue'], $sub['jobId']);
                }

                $conn->on('message', function (\Ratchet\RFC6455\Messaging\MessageInterface $msg): void {
                    $this->handleMessage((string) $msg);
                });

                // Explicitly nullable: an implicit nullable via `= null` is
                // deprecated in PHP 8.4 and would become an error later.
                $conn->on('close', function (?int $code = null, ?string $reason = null): void {
                    $this->connection = null;
                    $this->emit('disconnected', ['code' => $code, 'reason' => $reason]);

                    if ($this->running) {
                        $this->scheduleReconnect($code);
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
        // running is cleared before close, so the close handler never reaches
        // noteDisconnect. A socket that stayed up would otherwise keep its
        // spent reconnect count, and the next connect() would die after one
        // more drop. Refill it here, while connectedAt is still known.
        if ($this->connectionWasStable()) {
            $this->reconnectAttempts = 0;
            $this->unstableCloses = 0;
            $this->refreshedToken = null;
        }
        $this->connectedAt = null;
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

        // The backend serializes each event with its PascalCase enum variant
        // as `type` (e.g. "JobCompleted"). Map it to the SDK's dotted event
        // name (e.g. "job.completed") so typed handlers registered via on()
        // actually fire. Unknown types pass through unchanged.
        $rawType = $data['type'] ?? 'message';
        $type = self::EVENT_TYPE_MAP[$rawType] ?? $rawType;
        $this->logger->debug('WebSocket message received', ['type' => $type, 'rawType' => $rawType]);

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
     * Send subscribe command in the backend's ClientCommand shape.
     */
    private function sendSubscribe(?string $queue, ?string $jobId): void
    {
        $this->send([
            'cmd' => 'subscribe',
            'queue' => $queue,
            'job_id' => $jobId,
        ]);
    }

    /**
     * Send unsubscribe command in the backend's ClientCommand shape.
     */
    private function sendUnsubscribe(?string $queue, ?string $jobId): void
    {
        $this->send([
            'cmd' => 'unsubscribe',
            'queue' => $queue,
            'job_id' => $jobId,
        ]);
    }

    /**
     * The socket opened. The attempt counter stays as it is: clearing it here
     * made a server that accepts and then closes straight away reconnect
     * forever. {@see noteDisconnect()} resets it only after the socket has
     * stayed up.
     */
    private function noteConnected(): void
    {
        $this->connectedAt = microtime(true);
    }

    /**
     * Account for one dropped or refused connection.
     *
     * @return string|null Why reconnecting must stop, or null to schedule another attempt
     */
    private function noteDisconnect(?int $closeCode): ?string
    {
        // A socket that stayed up earned a fresh attempt budget, and the token
        // that kept it up is no longer "the fresh token from the last 1008".
        // A revocation after that is a new event and may refresh again.
        $stable = $this->connectionWasStable();
        $opened = $this->connectedAt !== null;
        if ($stable) {
            $this->reconnectAttempts = 0;
            $this->unstableCloses = 0;
            $this->refreshedToken = null;
        }
        $this->connectedAt = null;

        // 1008: this token is done (expired or revoked). Ask the provider to
        // drop its cache and log in again, then present that fresh token once.
        // Stop when there is no provider, or when the token we just minted is
        // rejected before the socket has stayed up.
        if ($closeCode === self::CLOSE_DO_NOT_REUSE_TOKEN) {
            if ($this->presentedToken !== null && $this->presentedToken !== '') {
                $this->rejectedTokens[$this->presentedToken] = true;
            }

            $freshTokenRejected = !$stable
                && $this->presentedToken !== null
                && $this->presentedToken === $this->refreshedToken;
            if ($freshTokenRejected || $this->tokenProvider === null) {
                $this->forceTokenRefresh = false;

                return 'WebSocket closed with 1008 (token expired or authorization revoked). The rejected access token will not be reused.';
            }

            $this->forceTokenRefresh = true;
        }

        // Every attempt grows the backoff delay; only a socket that opened and
        // dropped before it was stable counts toward the ceiling.
        $this->reconnectAttempts++;
        if ($opened && !$stable) {
            $this->unstableCloses++;
            if ($this->unstableCloses > self::MAX_RECONNECT_ATTEMPTS) {
                return 'WebSocket reconnect stopped after ' . self::MAX_RECONNECT_ATTEMPTS
                    . ' connections in a row closed right after opening';
            }
        }

        return null;
    }

    /**
     * Schedule a reconnection attempt.
     */
    private function scheduleReconnect(?int $closeCode = null): void
    {
        $stopReason = $this->noteDisconnect($closeCode);
        if ($stopReason !== null) {
            $this->running = false;
            $this->logger->warning($stopReason);
            $this->emit('error', ['error' => new RuntimeException($stopReason)]);

            return;
        }

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
        $token = $this->resolveAccessToken();
        // Remember which token this URL carries so a later close 1008 rejects
        // that value, even if the provider has moved on by then.
        $this->presentedToken = $token;

        $url = $this->wsUrl . '/api/v1/ws';

        // The backend /ws endpoint authenticates only a JWT in ?token=.
        // connect() guarantees an access token is present; there is no
        // api_key fallback because the endpoint does not accept it.
        if ($token !== null) {
            $url .= '?' . http_build_query(['token' => $token]);
        }

        return $url;
    }

    /**
     * The socket has been open long enough to count as healthy.
     */
    private function connectionWasStable(): bool
    {
        return $this->connectedAt !== null
            && (microtime(true) - $this->connectedAt) >= self::STABLE_CONNECTION_SECONDS;
    }

    /**
     * The JWT to present right now: the provider's (fresh each call) when one
     * is configured, else the static access token. A token the server rejected
     * with close 1008 is skipped. After that close the provider is asked to
     * mint a replacement first, so a cached copy of the rejected JWT is not
     * treated as terminal until the replacement has been rejected too.
     */
    private function resolveAccessToken(): ?string
    {
        if ($this->tokenProvider !== null) {
            $forceRefresh = $this->forceTokenRefresh;
            $token = $this->callTokenProvider($forceRefresh);
            if (is_string($token) && $token !== '') {
                if ($forceRefresh) {
                    $this->forceTokenRefresh = false;
                    if (isset($this->rejectedTokens[$token])) {
                        // Asked for a new JWT and got one the server already
                        // rejected. There is nothing new to dial.
                        $this->refreshedToken = $token;

                        return null;
                    }
                    $this->refreshedToken = $token;
                } elseif (isset($this->rejectedTokens[$token])) {
                    return null;
                }

                return $token;
            }
        }

        if (
            $this->accessToken !== null
            && $this->accessToken !== ''
            && !isset($this->rejectedTokens[$this->accessToken])
        ) {
            return $this->accessToken;
        }

        return null;
    }

    /**
     * Call the token provider. `true` is passed only when a refresh was
     * requested and the callable accepts an argument, so existing
     * zero-argument providers keep their old signature.
     */
    private function callTokenProvider(bool $forceRefresh): mixed
    {
        $provider = $this->tokenProvider;
        if (!is_callable($provider)) {
            return null;
        }

        $function = new ReflectionFunction(Closure::fromCallable($provider));
        if ($forceRefresh && $function->getNumberOfParameters() >= 1) {
            return $function->invoke(true);
        }

        return $function->invoke();
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
