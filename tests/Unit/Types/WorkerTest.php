<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Spooled\Types\Worker;
use Spooled\Types\WorkerList;

#[CoversClass(Worker::class)]
#[CoversClass(WorkerList::class)]
final class WorkerTest extends TestCase
{
    public function test_from_array_maps_public_rest_detail_fields(): void
    {
        $worker = Worker::fromArray([
            'id' => 'wrk-1',
            'organizationId' => 'org-1',
            'queueName' => 'emails',
            'queueNames' => ['emails', 'sms'],
            'hostname' => 'worker-1',
            'workerType' => 'php',
            'maxConcurrency' => 8,
            'currentJobs' => 2,
            'status' => 'healthy',
            'lastHeartbeat' => '2026-07-15T12:00:00Z',
            'metadata' => ['region' => 'eu'],
            'version' => '1.0.21',
            'registeredAt' => '2026-07-01T00:00:00Z',
            'updatedAt' => '2026-07-15T12:00:00Z',
        ]);

        $this->assertSame('wrk-1', $worker->id);
        $this->assertSame('org-1', $worker->organizationId);
        $this->assertSame('emails', $worker->queueName);
        $this->assertSame(['emails', 'sms'], $worker->queueNames);
        $this->assertSame('worker-1', $worker->hostname);
        $this->assertSame('php', $worker->workerType);
        $this->assertSame(8, $worker->maxConcurrency);
        $this->assertSame(2, $worker->currentJobs);
        $this->assertSame('healthy', $worker->status);
        $this->assertSame('2026-07-15T12:00:00Z', $worker->lastHeartbeat);
        $this->assertSame(['region' => 'eu'], $worker->metadata);
        $this->assertSame('1.0.21', $worker->version);
        $this->assertSame('2026-07-01T00:00:00Z', $worker->registeredAt);
        $this->assertSame('2026-07-15T12:00:00Z', $worker->updatedAt);
    }

    public function test_from_array_maps_register_response(): void
    {
        $worker = Worker::fromArray([
            'id' => 'wrk-reg',
            'queueName' => 'default',
            'leaseDurationSecs' => 60,
            'heartbeatIntervalSecs' => 15,
        ]);

        $this->assertSame('wrk-reg', $worker->id);
        $this->assertSame('default', $worker->queueName);
        $this->assertSame(['default'], $worker->queueNames);
        $this->assertSame(60, $worker->leaseDurationSecs);
        $this->assertSame(15, $worker->heartbeatIntervalSecs);
    }

    public function test_list_parses_summary_rows(): void
    {
        $list = WorkerList::fromArray([
            [
                'id' => 'wrk-1',
                'queueName' => 'q1',
                'hostname' => 'h1',
                'status' => 'healthy',
                'currentJobs' => 1,
                'maxConcurrency' => 5,
                'lastHeartbeat' => '2026-07-15T12:00:00Z',
            ],
        ]);

        $this->assertCount(1, $list->workers);
        $this->assertSame('q1', $list->workers[0]->queueName);
        $this->assertSame(5, $list->workers[0]->maxConcurrency);
        $this->assertSame(1, $list->workers[0]->currentJobs);
    }
}
