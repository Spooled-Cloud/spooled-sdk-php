<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\QueueStats;

#[CoversClass(QueueStats::class)]
final class QueueStatsTest extends TestCase
{
    #[Test]
    public function from_array_reads_api_job_count_fields(): void
    {
        $got = QueueStats::fromArray([
            'queueName' => 'emails',
            'pendingJobs' => 4,
            'processingJobs' => 2,
            'completedJobs24h' => 10,
            'failedJobs24h' => 1,
            'avgProcessingTimeMs' => 12.5,
            'activeWorkers' => 3,
        ]);

        $this->assertSame('emails', $got->name);
        $this->assertSame(4, $got->pending);
        $this->assertSame(2, $got->claimed);
        $this->assertSame(10, $got->completed);
        $this->assertSame(1, $got->failed);
        $this->assertSame(3, $got->activeWorkers);
        $this->assertSame(12.5, $got->avgProcessingTime);
    }
}
