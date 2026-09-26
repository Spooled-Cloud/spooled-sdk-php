<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Spooled\Realtime\SseClient;

/**
 * Not in the optional `realtime` group: SseClient only needs Guzzle, a hard
 * dependency, so these run with the default suite.
 */
final class SseClientTest extends TestCase
{
    public function testSseEventParsing(): void
    {
        // Test parsing of SSE event format
        $eventData = "event: job.completed\ndata: {\"jobId\":\"123\"}\n\n";

        // Parse event type
        preg_match('/^event:\s*(.+)$/m', $eventData, $eventMatch);
        $this->assertSame('job.completed', $eventMatch[1] ?? null);

        // Parse data
        preg_match('/^data:\s*(.+)$/m', $eventData, $dataMatch);
        $data = json_decode($dataMatch[1] ?? '{}', true);
        $this->assertSame('123', $data['jobId']);
    }

    public function testSseMultiLineData(): void
    {
        // SSE can have multiple data lines that should be concatenated
        $eventData = "data: line1\ndata: line2\n\n";

        preg_match_all('/^data:\s*(.+)$/m', $eventData, $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertSame('line1', $matches[1][0]);
        $this->assertSame('line2', $matches[1][1]);
    }

    public function testSseEventTypes(): void
    {
        $eventTypes = [
            'job.created',
            'job.completed',
            'job.failed',
            'queue.stats',
            'worker.heartbeat',
        ];

        foreach ($eventTypes as $type) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $type);
        }
    }

    public function testSseRetryParsing(): void
    {
        $eventData = "retry: 5000\ndata: {}\n\n";

        preg_match('/^retry:\s*(\d+)$/m', $eventData, $retryMatch);
        $this->assertSame('5000', $retryMatch[1] ?? null);
    }

    public function testSseIdParsing(): void
    {
        $eventData = "id: evt_123456\ndata: {}\n\n";

        preg_match('/^id:\s*(.+)$/m', $eventData, $idMatch);
        $this->assertSame('evt_123456', $idMatch[1] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(SseClient $client, string $frame): ?array
    {
        $method = new ReflectionMethod($client, 'parseEvent');

        /** @var array<string, mixed>|null */
        return $method->invoke($client, $frame);
    }

    public function testParseEventUnwrapsTheApiEnvelope(): void
    {
        $client = new SseClient('https://api.spooled.cloud', 'sp_test_key');
        $frame = "event: job.completed\n"
            . 'data: {"type":"JobCompleted","data":{"job_id":"job_1","queue_name":"orders","duration_ms":12}}'
            . "\n";

        $event = $this->parse($client, $frame);

        $this->assertNotNull($event);
        $this->assertSame('job.completed', $event['type']);
        $this->assertSame('job_1', $event['data']['job_id']);
        $this->assertSame('orders', $event['data']['queue_name']);
        $this->assertSame('JobCompleted', $event['envelope']['type']);
    }

    public function testQueueAndJobSubscriptionsFireForApiEvents(): void
    {
        $client = new SseClient('https://api.spooled.cloud', 'sp_test_key');
        $queueHits = [];
        $jobHits = [];
        $otherHits = [];
        $client->subscribeToQueue('orders', function (array $event) use (&$queueHits): void {
            $queueHits[] = $event;
        });
        $client->subscribeToJob('job_1', function (array $event) use (&$jobHits): void {
            $jobHits[] = $event;
        });
        $client->subscribeToQueue('emails', function (array $event) use (&$otherHits): void {
            $otherHits[] = $event;
        });

        $event = $this->parse(
            $client,
            "event: job.created\n"
            . 'data: {"type":"JobCreated","data":{"job_id":"job_1","queue_name":"orders","priority":0}}'
            . "\n",
        );
        $dispatch = new ReflectionMethod($client, 'dispatchToSubscriptions');
        $dispatch->invoke($client, $event);

        $this->assertCount(1, $queueHits);
        $this->assertCount(1, $jobHits);
        $this->assertCount(0, $otherHits);
        $this->assertSame('job_1', $queueHits[0]['data']['job_id']);
    }

    public function testNonEnvelopeDataIsLeftAlone(): void
    {
        $client = new SseClient('https://api.spooled.cloud', 'sp_test_key');
        $event = $this->parse($client, "event: custom\ndata: {\"jobId\":\"123\"}\n");

        $this->assertNotNull($event);
        $this->assertSame('123', $event['data']['jobId']);
        $this->assertArrayNotHasKey('envelope', $event);
    }

    public function testConnectDeliversEventsLineByLineAndStops(): void
    {
        $body = ": connected\n\n"
            . "event: system.health\n"
            . 'data: {"type":"SystemHealth","data":{"database":true,"redis":true}}' . "\n\n"
            . "event: job.created\n"
            . 'data: {"type":"JobCreated","data":{"job_id":"job_7","queue_name":"orders"}}' . "\n\n"
            . "event: job.completed\n"
            . 'data: {"type":"JobCompleted","data":{"job_id":"job_7","queue_name":"orders"}}' . "\n\n"
            . "event: job.created\n"
            . 'data: {"type":"JobCreated","data":{"job_id":"job_8","queue_name":"orders"}}' . "\n\n";
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], $body)]);
        $client = new SseClient(
            'https://api.spooled.cloud',
            'sp_test_key',
            null,
            null,
            new GuzzleClient(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.spooled.cloud']),
        );

        $seen = [];
        $client->subscribeToQueue('orders', function (array $event) use (&$seen, $client): void {
            $seen[] = $event['type'] . ':' . $event['data']['job_id'];
            if (count($seen) === 2) {
                $client->stop();
            }
        });
        (new ReflectionProperty($client, 'running'))->setValue($client, true);
        (new ReflectionMethod($client, 'connect'))->invoke($client);

        // Stops after the second matching event; the third is never dispatched.
        $this->assertSame(['job.created:job_7', 'job.completed:job_7'], $seen);
    }
}
