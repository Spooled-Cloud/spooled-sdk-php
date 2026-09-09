<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\ScheduleHistoryEntry;

#[CoversClass(ScheduleHistoryEntry::class)]
final class ScheduleHistoryEntryTest extends TestCase
{
    #[Test]
    public function from_array_reads_error_message_and_started_at(): void
    {
        $got = ScheduleHistoryEntry::fromArray([
            'id' => 'run_1',
            'scheduleId' => 'sch_1',
            'jobId' => 'job_1',
            'status' => 'failed',
            'errorMessage' => 'cron parse error',
            'startedAt' => '2024-01-01T00:00:00Z',
            'completedAt' => '2024-01-01T00:00:01Z',
        ]);

        $this->assertSame('cron parse error', $got->error);
        $this->assertSame('2024-01-01T00:00:00Z', $got->executedAt);
        $this->assertSame('job_1', $got->jobId);
        $this->assertSame('failed', $got->status);
    }
}
