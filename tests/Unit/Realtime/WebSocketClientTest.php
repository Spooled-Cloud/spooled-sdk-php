<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Spooled\Realtime\WebSocketClient;

/**
 * Offline unit tests for the WebSocket client's event mapping and the
 * client-command wire format. These use reflection to exercise message
 * handling and command sending without opening a real connection, so they
 * run in the default suite (no network group).
 */
final class WebSocketClientTest extends TestCase
{
    #[Test]
    public function it_dispatches_pascal_case_job_completed_to_dotted_handler(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $received = null;
        $client->on('job.completed', function (array $event) use (&$received): void {
            $received = $event;
        });

        // The backend serializes a completed job as {"type":"JobCompleted",...}.
        $this->invokeHandleMessage($client, (string) json_encode([
            'type' => 'JobCompleted',
            'data' => [
                'job_id' => 'job_abc',
                'queue_name' => 'emails',
                'duration_ms' => 12,
            ],
        ]));

        $this->assertNotNull($received, 'job.completed handler should fire for a JobCompleted event');
        $this->assertSame('JobCompleted', $received['type']);
        $this->assertSame('job_abc', $received['data']['job_id']);
    }

    #[Test]
    public function it_does_not_dispatch_to_a_mismatched_handler_but_does_dispatch_to_catch_all(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $wrongFired = false;
        $catchAllFired = false;

        $client->on('job.created', function (array $event) use (&$wrongFired): void {
            $wrongFired = true;
        });
        $client->on('message', function (array $event) use (&$catchAllFired): void {
            $catchAllFired = true;
        });

        $this->invokeHandleMessage($client, (string) json_encode([
            'type' => 'JobCompleted',
            'data' => ['job_id' => 'job_abc'],
        ]));

        $this->assertFalse($wrongFired, 'job.created handler must not fire for a JobCompleted event');
        $this->assertTrue($catchAllFired, 'the catch-all message handler must still fire');
    }

    #[Test]
    #[DataProvider('eventTypeMappingProvider')]
    public function it_maps_every_backend_variant_to_its_dotted_name(string $pascalCase, string $dotted): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $fired = false;
        $client->on($dotted, function (array $event) use (&$fired): void {
            $fired = true;
        });

        $this->invokeHandleMessage($client, (string) json_encode([
            'type' => $pascalCase,
            'data' => [],
        ]));

        $this->assertTrue($fired, "{$pascalCase} should dispatch to the {$dotted} handler");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function eventTypeMappingProvider(): array
    {
        return [
            'JobStatusChange' => ['JobStatusChange', 'job.status_changed'],
            'JobCreated' => ['JobCreated', 'job.created'],
            'JobCompleted' => ['JobCompleted', 'job.completed'],
            'JobFailed' => ['JobFailed', 'job.failed'],
            'QueueStats' => ['QueueStats', 'queue.stats'],
            'WorkerHeartbeat' => ['WorkerHeartbeat', 'worker.heartbeat'],
            'WorkerRegistered' => ['WorkerRegistered', 'worker.registered'],
            'WorkerDeregistered' => ['WorkerDeregistered', 'worker.deregistered'],
            'SystemHealth' => ['SystemHealth', 'system.health'],
            'Ping' => ['Ping', 'ping'],
            'Error' => ['Error', 'error'],
        ];
    }

    #[Test]
    public function subscribe_to_queue_sends_backend_client_command(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $fake = $this->attachFakeConnection($client);

        $client->subscribeToQueue('emails');

        $this->assertCount(1, $fake->sent);
        $payload = json_decode($fake->sent[0], true);

        $this->assertSame('subscribe', $payload['cmd']);
        $this->assertSame('emails', $payload['queue']);
        $this->assertNull($payload['job_id']);
    }

    #[Test]
    public function subscribe_to_job_sends_backend_client_command(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $fake = $this->attachFakeConnection($client);

        $client->subscribeToJob('job_abc');

        $this->assertCount(1, $fake->sent);
        $payload = json_decode($fake->sent[0], true);

        $this->assertSame('subscribe', $payload['cmd']);
        $this->assertNull($payload['queue']);
        $this->assertSame('job_abc', $payload['job_id']);
    }

    #[Test]
    public function unsubscribe_sends_backend_client_command(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $fake = $this->attachFakeConnection($client);

        $client->unsubscribe(queue: 'emails');

        $this->assertCount(1, $fake->sent);
        $payload = json_decode($fake->sent[0], true);

        $this->assertSame('unsubscribe', $payload['cmd']);
        $this->assertSame('emails', $payload['queue']);
        $this->assertNull($payload['job_id']);
    }

    private function invokeHandleMessage(WebSocketClient $client, string $message): void
    {
        $method = new ReflectionMethod($client, 'handleMessage');
        $method->setAccessible(true);
        $method->invoke($client, $message);
    }

    /**
     * Attach a fake connection that captures sent frames, so subscribe/
     * unsubscribe emit their commands immediately (as they do when already
     * connected).
     */
    private function attachFakeConnection(WebSocketClient $client): object
    {
        $fake = new class () {
            /** @var list<string> */
            public array $sent = [];

            public function send(string $data): void
            {
                $this->sent[] = $data;
            }
        };

        $property = new ReflectionProperty($client, 'connection');
        $property->setAccessible(true);
        $property->setValue($client, $fake);

        return $fake;
    }

    #[Test]
    public function an_accept_then_immediate_close_accumulates_reconnect_attempts(): void
    {
        // Opening used to zero the counter, so a server that accepts and then
        // closes (close 1011, event channel closed) reconnected forever.
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );

        $max = $this->constant($client, 'MAX_RECONNECT_ATTEMPTS');
        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $this->invoke($client, 'noteConnected');
            $this->assertNull($this->invoke($client, 'noteDisconnect', 1011));
            $this->assertSame($attempt, $this->propertyValue($client, 'reconnectAttempts'));
        }

        $this->invoke($client, 'noteConnected');
        $stop = $this->invoke($client, 'noteDisconnect', 1011);
        $this->assertIsString($stop);
        $this->assertStringContainsString((string) $max, $stop);
    }

    #[Test]
    public function a_connection_that_stayed_up_resets_the_reconnect_counter(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );
        $this->setProperty($client, 'reconnectAttempts', 7);
        $this->setProperty($client, 'connectedAt', microtime(true) - 31);

        $this->assertNull($this->invoke($client, 'noteDisconnect', 1011));
        $this->assertSame(1, $this->propertyValue($client, 'reconnectAttempts'));
    }

    #[Test]
    public function reconnect_stops_after_the_attempt_ceiling(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );
        $max = $this->constant($client, 'MAX_RECONNECT_ATTEMPTS');
        $this->setProperty($client, 'reconnectAttempts', $max);
        $this->setProperty($client, 'unstableCloses', $max);
        $this->setProperty($client, 'connectedAt', microtime(true));
        $this->setProperty($client, 'running', true);

        $error = null;
        $client->on('error', static function (array $event) use (&$error): void {
            $error = $event['error'];
        });

        $this->invoke($client, 'scheduleReconnect', 1011);

        $this->assertFalse($this->propertyValue($client, 'running'));
        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString((string) $max, $error->getMessage());
    }

    #[Test]
    public function connect_failures_back_off_without_ever_stopping(): void
    {
        // A backend outage refuses the connection; the socket never opens.
        // Long-running workers must keep retrying until the server is back.
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );
        $max = $this->constant($client, 'MAX_RECONNECT_ATTEMPTS');
        for ($attempt = 1; $attempt <= $max * 5; $attempt++) {
            $this->assertNull($this->invoke($client, 'noteDisconnect', null));
        }
        $this->assertSame(0, $this->propertyValue($client, 'unstableCloses'));
    }

    #[Test]
    public function close_1008_does_not_reuse_the_rejected_access_token(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'dead-jwt',
        );
        $this->setProperty($client, 'presentedToken', 'dead-jwt');
        $this->setProperty($client, 'running', true);

        $error = null;
        $client->on('error', static function (array $event) use (&$error): void {
            $error = $event['error'];
        });

        $this->invoke($client, 'scheduleReconnect', 1008);

        $this->assertFalse($this->propertyValue($client, 'running'));
        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('1008', $error->getMessage());
        $this->assertSame('wss://api.spooled.cloud/api/v1/ws', $this->invoke($client, 'buildUrl'));
        $this->assertStringNotContainsString('dead-jwt', (string) $this->invoke($client, 'buildUrl'));
    }

    #[Test]
    public function close_1008_reconnects_when_the_provider_returns_a_different_token(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'dead-jwt',
            tokenProvider: static fn (): string => 'fresh-jwt',
        );
        $this->setProperty($client, 'presentedToken', 'dead-jwt');

        $this->assertNull($this->invoke($client, 'noteDisconnect', 1008));
        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws?token=fresh-jwt',
            $this->invoke($client, 'buildUrl'),
        );
    }

    #[Test]
    public function close_1008_does_not_dial_when_the_provider_repeats_the_rejected_token(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'also-dead',
            tokenProvider: static fn (): string => 'dead-jwt',
        );
        $this->setProperty($client, 'presentedToken', 'dead-jwt');
        $this->setProperty($client, 'running', true);
        $this->invoke($client, 'noteDisconnect', 1008);

        $error = null;
        $client->on('error', static function (array $event) use (&$error): void {
            $error = $event['error'];
        });

        // No socket: the only token left is the one close 1008 rejected.
        $this->invoke($client, 'openConnection');

        $this->assertFalse($this->propertyValue($client, 'running'));
        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('1008', $error->getMessage());
        $this->assertNull($this->propertyValue($client, 'presentedToken'));
    }

    #[Test]
    public function close_1008_forces_a_refresh_and_dials_the_new_token(): void
    {
        // A provider that still holds the rejected JWT must be asked to mint
        // another. Stopping on the repeated string is what stranded a client
        // whose API-key JWT was cached for the rest of its lifetime.
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            tokenProvider: static function (bool $forceRefresh = false): string {
                return $forceRefresh ? 'fresh-jwt' : 'dead-jwt';
            },
        );
        $this->setProperty($client, 'presentedToken', 'dead-jwt');
        $this->setProperty($client, 'running', true);

        $this->assertNull($this->invoke($client, 'noteDisconnect', 1008));
        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws?token=fresh-jwt',
            $this->invoke($client, 'buildUrl'),
        );
        $this->assertTrue($this->propertyValue($client, 'running'));
    }

    #[Test]
    public function close_1008_stops_when_the_fresh_token_is_rejected_too(): void
    {
        $refreshes = 0;
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            tokenProvider: static function (bool $forceRefresh = false) use (&$refreshes): string {
                if ($forceRefresh) {
                    $refreshes++;

                    return 'fresh-jwt';
                }

                return 'dead-jwt';
            },
        );
        $this->setProperty($client, 'presentedToken', 'dead-jwt');

        $this->assertNull($this->invoke($client, 'noteDisconnect', 1008));
        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws?token=fresh-jwt',
            $this->invoke($client, 'buildUrl'),
        );
        $this->assertSame(1, $refreshes);

        // The replacement was rejected before the socket stayed up.
        $stop = $this->invoke($client, 'noteDisconnect', 1008);
        $this->assertIsString($stop);
        $this->assertStringContainsString('1008', $stop);

        // Neither rejected token is presented, and there is no third login.
        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws',
            $this->invoke($client, 'buildUrl'),
        );
        $this->assertSame(1, $refreshes);
    }

    #[Test]
    public function close_1008_after_a_stable_connection_refreshes_again(): void
    {
        $issued = 0;
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            tokenProvider: static function (bool $forceRefresh = false) use (&$issued): string {
                if ($forceRefresh) {
                    $issued++;
                }

                return 'jwt-' . $issued;
            },
        );

        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws?token=jwt-0',
            $this->invoke($client, 'buildUrl'),
        );
        $this->invoke($client, 'noteConnected');
        $this->assertNull($this->invoke($client, 'noteDisconnect', 1008));
        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws?token=jwt-1',
            $this->invoke($client, 'buildUrl'),
        );

        // The replacement stayed up, so a later revocation may refresh again.
        $this->invoke($client, 'noteConnected');
        $this->setProperty($client, 'connectedAt', microtime(true) - 31);
        $this->assertNull($this->invoke($client, 'noteDisconnect', 1008));
        $this->assertSame(
            'wss://api.spooled.cloud/api/v1/ws?token=jwt-2',
            $this->invoke($client, 'buildUrl'),
        );
    }

    #[Test]
    public function disconnect_after_a_stable_connection_resets_reconnect_attempts(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );
        $this->setProperty($client, 'reconnectAttempts', 7);
        $this->setProperty($client, 'connectedAt', microtime(true) - 31);
        $this->setProperty($client, 'running', true);

        $client->disconnect();

        $this->assertSame(0, $this->propertyValue($client, 'reconnectAttempts'));
        $this->assertFalse($this->propertyValue($client, 'running'));
        $this->assertNull($this->propertyValue($client, 'connectedAt'));
    }

    #[Test]
    public function disconnect_before_the_connection_is_stable_keeps_reconnect_attempts(): void
    {
        $client = new WebSocketClient(
            wsUrl: 'wss://api.spooled.cloud',
            accessToken: 'jwt-token',
        );
        $this->setProperty($client, 'reconnectAttempts', 7);
        $this->setProperty($client, 'connectedAt', microtime(true));
        $this->setProperty($client, 'running', true);

        $client->disconnect();

        $this->assertSame(7, $this->propertyValue($client, 'reconnectAttempts'));
        $this->assertFalse($this->propertyValue($client, 'running'));
    }

    private function invoke(WebSocketClient $client, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($client, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($client, ...$args);
    }

    private function setProperty(WebSocketClient $client, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($client, $name);
        $property->setAccessible(true);
        $property->setValue($client, $value);
    }

    private function propertyValue(WebSocketClient $client, string $name): mixed
    {
        $property = new ReflectionProperty($client, $name);
        $property->setAccessible(true);

        return $property->getValue($client);
    }

    private function constant(WebSocketClient $client, string $name): int
    {
        return (new ReflectionClass($client))->getReflectionConstant($name)->getValue();
    }
}
