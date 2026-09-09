<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\DashboardStats;

#[CoversClass(DashboardStats::class)]
final class DashboardStatsTest extends TestCase
{
    #[Test]
    public function from_array_reads_dashboard_job_summary_fields(): void
    {
        $got = DashboardStats::fromArray([
            'jobs' => [
                'total' => 40,
                'pending' => 3,
                'processing' => 2,
                'completed24h' => 25,
                'failed24h' => 4,
                'deadletter' => 1,
                'avgWaitTimeMs' => 12.5,
                'avgProcessingTimeMs' => 80.0,
            ],
            'workers' => [
                'total' => 6,
                'healthy' => 5,
            ],
        ]);

        $this->assertSame(40, $got->totalJobs);
        $this->assertSame(3, $got->pendingJobs);
        $this->assertSame(2, $got->processingJobs);
        $this->assertSame(25, $got->completedJobs);
        $this->assertSame(4, $got->failedJobs);
        $this->assertSame(1, $got->deadLetterJobs);
        $this->assertSame(6, $got->totalWorkers);
        $this->assertSame(5, $got->healthyWorkers);
        $this->assertSame(12.5, $got->avgWaitTimeMs);
        $this->assertSame(80.0, $got->avgProcessingTimeMs);
    }

    #[Test]
    public function from_array_reads_snake_case_dashboard_job_summary_fields(): void
    {
        $got = DashboardStats::fromArray([
            'jobs' => [
                'completed_24h' => 9,
                'failed_24h' => 2,
                'avg_wait_time_ms' => 3.5,
                'avg_processing_time_ms' => 11.0,
            ],
        ]);

        $this->assertSame(9, $got->completedJobs);
        $this->assertSame(2, $got->failedJobs);
        $this->assertSame(3.5, $got->avgWaitTimeMs);
        $this->assertSame(11.0, $got->avgProcessingTimeMs);
    }
}
