<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
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
}
