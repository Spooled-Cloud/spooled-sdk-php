<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Worker\JobContext;
use Spooled\Worker\SpooledWorker;

#[CoversClass(JobContext::class)]
final class JobContextTest extends TestCase
{
    private function createContext(
        string $jobId = 'job-123',
        string $queueName = 'test-queue',
        array $payload = [],
        int $retryCount = 0,
        int $maxRetries = 3,
        array $metadata = [],
    ): JobContext {
        $worker = $this->createMock(SpooledWorker::class);

        return new JobContext(
            jobId: $jobId,
            queueName: $queueName,
            payload: $payload,
            retryCount: $retryCount,
            maxRetries: $maxRetries,
            metadata: $metadata,
            worker: $worker,
        );
    }

    #[Test]
    public function it_exposes_job_properties(): void
    {
        $ctx = $this->createContext(
            jobId: 'job-456',
            queueName: 'my-queue',
            payload: ['key' => 'value'],
            retryCount: 1,
            maxRetries: 5,
        );

        $this->assertSame('job-456', $ctx->jobId);
        $this->assertSame('my-queue', $ctx->queueName);
        $this->assertSame(['key' => 'value'], $ctx->payload);
        $this->assertSame(1, $ctx->retryCount);
        $this->assertSame(5, $ctx->maxRetries);
    }

    #[Test]
    public function it_tracks_result(): void
    {
        $ctx = $this->createContext();

        $this->assertNull($ctx->getResult());

        $ctx->setResult(['success' => true, 'data' => 'test']);

        $this->assertSame(['success' => true, 'data' => 'test'], $ctx->getResult());
    }

    #[Test]
    public function it_detects_retry(): void
    {
        $firstAttempt = $this->createContext(retryCount: 0);
        $retry = $this->createContext(retryCount: 1);

        $this->assertFalse($firstAttempt->isRetry());
        $this->assertTrue($retry->isRetry());
    }

    #[Test]
    public function it_detects_last_attempt(): void
    {
        $firstOf3 = $this->createContext(retryCount: 0, maxRetries: 3);
        $lastOf3 = $this->createContext(retryCount: 3, maxRetries: 3);
        $overMax = $this->createContext(retryCount: 5, maxRetries: 3);

        $this->assertFalse($firstOf3->isLastAttempt());
        $this->assertTrue($lastOf3->isLastAttempt());
        $this->assertTrue($overMax->isLastAttempt());
    }

    #[Test]
    public function it_calculates_remaining_retries(): void
    {
        $fresh = $this->createContext(retryCount: 0, maxRetries: 3);
        $partial = $this->createContext(retryCount: 1, maxRetries: 3);
        $exhausted = $this->createContext(retryCount: 3, maxRetries: 3);
        $over = $this->createContext(retryCount: 5, maxRetries: 3);

        $this->assertSame(3, $fresh->getRemainingRetries());
        $this->assertSame(2, $partial->getRemainingRetries());
        $this->assertSame(0, $exhausted->getRemainingRetries());
        $this->assertSame(0, $over->getRemainingRetries());
    }

    #[Test]
    public function it_gets_payload_values(): void
    {
        $ctx = $this->createContext(payload: [
            'name' => 'John',
            'age' => 30,
            'active' => true,
        ]);

        $this->assertSame('John', $ctx->get('name'));
        $this->assertSame(30, $ctx->get('age'));
        $this->assertTrue($ctx->get('active'));
        $this->assertNull($ctx->get('missing'));
        $this->assertSame('default', $ctx->get('missing', 'default'));
    }

    #[Test]
    public function it_checks_payload_has_key(): void
    {
        $ctx = $this->createContext(payload: [
            'exists' => 'value',
            'nullValue' => null,
        ]);

        $this->assertTrue($ctx->has('exists'));
        $this->assertTrue($ctx->has('nullValue'));
        $this->assertFalse($ctx->has('missing'));
    }

    #[Test]
    public function it_gets_metadata_values(): void
    {
        $ctx = $this->createContext(metadata: [
            'source' => 'api',
            'version' => '1.0',
        ]);

        $this->assertSame('api', $ctx->getMeta('source'));
        $this->assertSame('1.0', $ctx->getMeta('version'));
        $this->assertNull($ctx->getMeta('missing'));
        $this->assertSame('fallback', $ctx->getMeta('missing', 'fallback'));
    }

    #[Test]
    public function it_checks_shutdown_status(): void
    {
        $worker = $this->createMock(SpooledWorker::class);
        $worker->method('isShuttingDown')->willReturn(false);

        $ctx = new JobContext(
            jobId: 'job-123',
            queueName: 'queue',
            payload: [],
            retryCount: 0,
            maxRetries: 3,
            metadata: [],
            worker: $worker,
        );

        $this->assertFalse($ctx->isShuttingDown());
    }
}
