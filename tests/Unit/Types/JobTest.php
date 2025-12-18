<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\CreateJobParams;
use Spooled\Types\Job;
use Spooled\Types\JobList;
use Spooled\Types\JobStats;

#[CoversClass(Job::class)]
#[CoversClass(JobList::class)]
#[CoversClass(JobStats::class)]
#[CoversClass(CreateJobParams::class)]
final class JobTest extends TestCase
{
    #[Test]
    public function it_creates_job_from_array(): void
    {
        $data = [
            'id' => 'job-123',
            'queueName' => 'test-queue',
            'status' => 'pending',
            'payload' => ['key' => 'value'],
            'priority' => 10,
            'retryCount' => 0,
            'maxRetries' => 3,
            'createdAt' => '2024-01-01T00:00:00Z',
        ];

        $job = Job::fromArray($data);

        $this->assertSame('job-123', $job->id);
        $this->assertSame('test-queue', $job->queueName);
        $this->assertSame('pending', $job->status);
        $this->assertSame(['key' => 'value'], $job->payload);
        $this->assertSame(10, $job->priority);
        $this->assertSame(0, $job->retryCount);
        $this->assertSame(3, $job->maxRetries);
    }

    #[Test]
    public function it_handles_missing_optional_fields(): void
    {
        $data = [
            'id' => 'job-123',
            'queueName' => 'test-queue',
            'status' => 'pending',
            'payload' => [],
        ];

        $job = Job::fromArray($data);

        $this->assertSame('job-123', $job->id);
        $this->assertNull($job->workerId);
        $this->assertNull($job->scheduleId);
        $this->assertNull($job->error);
        $this->assertNull($job->result);
    }

    #[Test]
    public function it_handles_alternative_field_names(): void
    {
        $data = [
            'id' => 'job-123',
            'queue' => 'test-queue', // Alternative name
            'status' => 'pending',
            'payload' => [],
            'retry_count' => 2, // Snake case
            'max_retries' => 5, // Snake case
        ];

        $job = Job::fromArray($data);

        $this->assertSame('test-queue', $job->queueName);
        $this->assertSame(2, $job->retryCount);
        $this->assertSame(5, $job->maxRetries);
    }

    #[Test]
    public function it_converts_job_to_array(): void
    {
        $data = [
            'id' => 'job-123',
            'queueName' => 'test-queue',
            'status' => 'completed',
            'payload' => ['result' => 'success'],
            'priority' => 5,
            'retryCount' => 1,
            'maxRetries' => 3,
        ];

        $job = Job::fromArray($data);
        $array = $job->toArray();

        $this->assertSame('job-123', $array['id']);
        $this->assertSame('test-queue', $array['queueName']);
        $this->assertSame('completed', $array['status']);
        $this->assertArrayNotHasKey('workerId', $array); // Null values filtered
    }

    #[Test]
    public function it_creates_job_list_from_array(): void
    {
        $data = [
            'jobs' => [
                ['id' => 'job-1', 'queueName' => 'queue', 'status' => 'pending', 'payload' => []],
                ['id' => 'job-2', 'queueName' => 'queue', 'status' => 'completed', 'payload' => []],
            ],
            'total' => 100,
            'page' => 1,
            'pageSize' => 20,
            'hasMore' => true,
        ];

        $list = JobList::fromArray($data);

        $this->assertCount(2, $list->jobs);
        $this->assertSame('job-1', $list->jobs[0]->id);
        $this->assertSame('job-2', $list->jobs[1]->id);
        $this->assertSame(100, $list->total);
        $this->assertSame(1, $list->page);
        $this->assertSame(20, $list->pageSize);
        $this->assertTrue($list->hasMore);
    }

    #[Test]
    public function it_creates_job_stats_from_array(): void
    {
        $data = [
            'pending' => 10,
            'claimed' => 5,
            'completed' => 100,
            'failed' => 3,
            'cancelled' => 2,
            'scheduled' => 15,
            'total' => 135,
        ];

        $stats = JobStats::fromArray($data);

        $this->assertSame(10, $stats->pending);
        $this->assertSame(5, $stats->claimed);
        $this->assertSame(100, $stats->completed);
        $this->assertSame(3, $stats->failed);
        $this->assertSame(2, $stats->cancelled);
        $this->assertSame(15, $stats->scheduled);
        $this->assertSame(135, $stats->total);
    }

    #[Test]
    public function it_creates_job_params(): void
    {
        $params = new CreateJobParams(
            queue: 'test-queue',
            payload: ['key' => 'value'],
            priority: 10,
            maxRetries: 5,
            scheduledFor: '2024-12-31T23:59:59Z',
            tags: ['tag1', 'tag2'],
            metadata: ['source' => 'test'],
        );

        $array = $params->toArray();

        $this->assertSame('test-queue', $array['queue']);
        $this->assertSame(['key' => 'value'], $array['payload']);
        $this->assertSame(10, $array['priority']);
        $this->assertSame(5, $array['maxRetries']);
        $this->assertSame('2024-12-31T23:59:59Z', $array['scheduledFor']);
        $this->assertSame(['tag1', 'tag2'], $array['tags']);
        $this->assertSame(['source' => 'test'], $array['metadata']);
    }

    #[Test]
    public function it_filters_null_values_from_params(): void
    {
        $params = new CreateJobParams(
            queue: 'test-queue',
            payload: ['key' => 'value'],
        );

        $array = $params->toArray();

        $this->assertArrayHasKey('queue', $array);
        $this->assertArrayHasKey('payload', $array);
        $this->assertArrayNotHasKey('scheduledFor', $array);
        $this->assertArrayNotHasKey('tags', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }
}
