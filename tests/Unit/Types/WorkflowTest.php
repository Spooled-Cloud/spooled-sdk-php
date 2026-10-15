<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\Workflow;
use Spooled\Types\WorkflowJob;
use Spooled\Types\WorkflowJobDefinition;

#[CoversClass(Workflow::class)]
#[CoversClass(WorkflowJob::class)]
#[CoversClass(WorkflowJobDefinition::class)]
final class WorkflowTest extends TestCase
{
    #[Test]
    public function from_array_reads_progress_counts_from_get_detail(): void
    {
        $got = Workflow::fromArray([
            'id' => 'wf_1',
            'name' => 'ETL',
            'status' => 'running',
            'organizationId' => 'org_1',
            'jobs' => [
                ['id' => 'job_1', 'status' => 'completed'],
                ['id' => 'job_2', 'status' => 'pending'],
            ],
            'progress' => [
                'total' => 2,
                'completed' => 1,
                'failed' => 0,
                'pending' => 1,
                'processing' => 0,
            ],
            'createdAt' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertSame(2, $got->totalJobs);
        $this->assertSame(1, $got->completedJobs);
        $this->assertSame(0, $got->failedJobs);
    }

    #[Test]
    public function from_array_still_reads_list_total_jobs(): void
    {
        $got = Workflow::fromArray([
            'id' => 'wf_1',
            'name' => 'ETL',
            'status' => 'running',
            'totalJobs' => 4,
            'completedJobs' => 3,
            'failedJobs' => 1,
            'createdAt' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertSame(4, $got->totalJobs);
        $this->assertSame(3, $got->completedJobs);
        $this->assertSame(1, $got->failedJobs);
    }

    #[Test]
    public function from_array_keeps_non_object_json_metadata(): void
    {
        $got = Workflow::fromArray([
            'id' => 'wf_json',
            'name' => 'ETL',
            'status' => 'pending',
            'metadata' => 3,
        ]);

        $this->assertSame(3, $got->metadata);
    }

    #[Test]
    public function job_from_array_maps_timeout_ms_to_seconds(): void
    {
        $failed = WorkflowJob::fromArray([
            'id' => 'job_1',
            'workflowId' => 'wf_1',
            'queue' => 'etl',
            'status' => 'failed',
            'timeoutMs' => 120000,
            'error' => ['type' => 'JobError', 'message' => 'upstream 502'],
        ]);
        $fractional = WorkflowJob::fromArray([
            'id' => 'job_2',
            'timeout_ms' => 1500,
        ]);
        $subSecond = WorkflowJob::fromArray([
            'id' => 'job_3',
            'timeoutMs' => 999,
        ]);
        $absent = WorkflowJob::fromArray([
            'id' => 'job_4',
        ]);
        $notANumber = WorkflowJob::fromArray([
            'id' => 'job_5',
            'timeoutMs' => '120000',
        ]);

        // 120s was stored; without this read the detail came back with no timeout.
        $this->assertSame(120, $failed->timeoutSeconds);
        $this->assertSame('upstream 502', $failed->error);
        // Same conversion as the Node and Python clients: floor, and never 0.
        $this->assertSame(1, $fractional->timeoutSeconds);
        $this->assertSame(1, $subSecond->timeoutSeconds);
        $this->assertNull($absent->timeoutSeconds);
        $this->assertNull($notANumber->timeoutSeconds);
    }

    #[Test]
    public function job_definition_sends_timeout_seconds_and_omits_it_when_unset(): void
    {
        $withTimeout = new WorkflowJobDefinition(
            name: 'charge',
            queue: 'payments',
            payload: ['amount' => 10],
            timeoutSeconds: 120,
        );
        $withoutTimeout = new WorkflowJobDefinition(
            name: 'charge',
            queue: 'payments',
            payload: ['amount' => 10],
        );

        $this->assertSame(120, $withTimeout->toArray()['timeoutSeconds']);
        $this->assertArrayNotHasKey('timeoutSeconds', $withoutTimeout->toArray());
    }
}
