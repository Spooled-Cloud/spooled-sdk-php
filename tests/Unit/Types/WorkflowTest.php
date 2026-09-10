<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\Workflow;

#[CoversClass(Workflow::class)]
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
}
