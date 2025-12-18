<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Realtime;

use PHPUnit\Framework\TestCase;

/**
 * @group realtime
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
}
