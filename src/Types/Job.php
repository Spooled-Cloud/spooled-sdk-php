<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Job status enumeration.
 */
enum JobStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case SCHEDULED = 'scheduled';
}

/**
 * Represents a job in the queue.
 */
final readonly class Job
{
    public function __construct(
        public string $id,
        public string $queueName,
        public string $status,
        /** @var array<string, mixed> */
        public array $payload,
        public int $priority,
        public int $retryCount,
        public int $maxRetries,
        public ?string $workerId,
        public ?string $workerName,
        public ?string $scheduleId,
        public ?string $workflowId,
        public ?string $parentJobId,
        public ?string $organizationId,
        public ?string $error,
        /** @var array<string, mixed>|null */
        public ?array $result,
        /** @var array<string>|null */
        public ?array $tags,
        /** @var array<string, mixed>|null */
        public ?array $metadata,
        public ?string $idempotencyKey,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $scheduledFor,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $claimedAt,
        public ?string $leaseExpiresAt,
        public ?string $expiresAt,
    ) {
    }

    /**
     * Create from API response array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            queueName: (string) ($data['queueName'] ?? $data['queue'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            priority: (int) ($data['priority'] ?? 0),
            retryCount: (int) ($data['retryCount'] ?? $data['retry_count'] ?? 0),
            maxRetries: (int) ($data['maxRetries'] ?? $data['max_retries'] ?? 3),
            workerId: isset($data['workerId']) ? (string) $data['workerId'] : null,
            workerName: isset($data['workerName']) ? (string) $data['workerName'] : null,
            scheduleId: isset($data['scheduleId']) ? (string) $data['scheduleId'] : null,
            workflowId: isset($data['workflowId']) ? (string) $data['workflowId'] : null,
            parentJobId: isset($data['parentJobId']) ? (string) $data['parentJobId'] : null,
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            result: is_array($data['result'] ?? null) ? $data['result'] : null,
            tags: isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            idempotencyKey: isset($data['idempotencyKey']) ? (string) $data['idempotencyKey'] : null,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) ? (string) $data['updatedAt'] : null,
            scheduledFor: isset($data['scheduledFor']) ? (string) $data['scheduledFor']
                : (isset($data['scheduledAt']) ? (string) $data['scheduledAt'] : null),
            startedAt: isset($data['startedAt']) ? (string) $data['startedAt'] : null,
            completedAt: isset($data['completedAt']) ? (string) $data['completedAt'] : null,
            claimedAt: isset($data['claimedAt']) ? (string) $data['claimedAt'] : null,
            leaseExpiresAt: isset($data['leaseExpiresAt']) ? (string) $data['leaseExpiresAt'] : null,
            expiresAt: isset($data['expiresAt']) ? (string) $data['expiresAt'] : null,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'queueName' => $this->queueName,
            'status' => $this->status,
            'payload' => $this->payload,
            'priority' => $this->priority,
            'retryCount' => $this->retryCount,
            'maxRetries' => $this->maxRetries,
            'workerId' => $this->workerId,
            'workerName' => $this->workerName,
            'scheduleId' => $this->scheduleId,
            'workflowId' => $this->workflowId,
            'parentJobId' => $this->parentJobId,
            'organizationId' => $this->organizationId,
            'error' => $this->error,
            'result' => $this->result,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'idempotencyKey' => $this->idempotencyKey,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'scheduledFor' => $this->scheduledFor,
            'startedAt' => $this->startedAt,
            'completedAt' => $this->completedAt,
            'claimedAt' => $this->claimedAt,
            'leaseExpiresAt' => $this->leaseExpiresAt,
        ], fn ($v) => $v !== null);
    }
}

/**
 * Parameters for creating a job.
 */
final readonly class CreateJobParams
{
    public function __construct(
        public string $queue,
        /** @var array<string, mixed> */
        public array $payload,
        public int $priority = 0,
        public int $maxRetries = 3,
        public ?string $scheduledFor = null,
        /** @var array<string>|null */
        public ?array $tags = null,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
        public ?string $idempotencyKey = null,
        /** @var array<string>|null */
        public ?array $dependencies = null,
    ) {
    }

    /**
     * Convert to array for API request.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'queue' => $this->queue,
            'payload' => $this->payload,
            'priority' => $this->priority,
            'maxRetries' => $this->maxRetries,
            'scheduledFor' => $this->scheduledFor,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'idempotencyKey' => $this->idempotencyKey,
            'dependencies' => $this->dependencies,
        ], fn ($v) => $v !== null);
    }
}

/**
 * Job list response.
 */
final readonly class JobList
{
    /**
     * @param array<Job> $jobs
     */
    public function __construct(
        public array $jobs,
        public int $total,
        public int $page,
        public int $pageSize,
        public bool $hasMore,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Handle both wrapped and raw array responses
        // Wrapped: { "jobs": [...], "total": 10, ... }
        // Raw: [ {...}, {...}, ... ]
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $jobsData = $isRawArray ? $data : ($data['jobs'] ?? $data['data'] ?? []);

        $jobs = array_map(
            fn (array $item) => Job::fromArray($item),
            $jobsData,
        );

        return new self(
            jobs: $jobs,
            total: (int) ($data['total'] ?? $data['totalJobs'] ?? count($jobs)),
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['pageSize'] ?? $data['limit'] ?? count($jobs)),
            hasMore: (bool) ($data['hasMore'] ?? (count($jobs) > 0 && count($jobs) === (int) ($data['limit'] ?? 100))),
        );
    }
}

/**
 * Job statistics.
 */
final readonly class JobStats
{
    public function __construct(
        public int $pending,
        public int $claimed,
        public int $completed,
        public int $failed,
        public int $cancelled,
        public int $scheduled,
        public int $deadletter,
        public int $processing,
        public int $total,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pending: (int) ($data['pending'] ?? 0),
            claimed: (int) ($data['claimed'] ?? $data['processing'] ?? 0),
            completed: (int) ($data['completed'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
            cancelled: (int) ($data['cancelled'] ?? 0),
            scheduled: (int) ($data['scheduled'] ?? 0),
            deadletter: (int) ($data['deadletter'] ?? 0),
            processing: (int) ($data['processing'] ?? $data['claimed'] ?? 0),
            total: (int) ($data['total'] ?? 0),
        );
    }
}

/**
 * Claimed job for worker processing.
 */
final readonly class ClaimedJob
{
    public function __construct(
        public string $id,
        public string $queueName,
        /** @var array<string, mixed> */
        public array $payload,
        public int $retryCount,
        public int $maxRetries,
        public int $timeoutSeconds,
        public ?string $leaseExpiresAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            queueName: (string) ($data['queueName'] ?? $data['queue'] ?? ''),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            retryCount: (int) ($data['retryCount'] ?? $data['retry_count'] ?? 0),
            maxRetries: (int) ($data['maxRetries'] ?? $data['max_retries'] ?? 3),
            timeoutSeconds: (int) ($data['timeoutSeconds'] ?? $data['timeout_seconds'] ?? 300),
            leaseExpiresAt: isset($data['leaseExpiresAt']) ? (string) $data['leaseExpiresAt'] : null,
        );
    }
}

/**
 * Result of claiming jobs.
 */
final readonly class ClaimJobsResult
{
    /**
     * @param array<ClaimedJob> $jobs
     */
    public function __construct(
        public array $jobs,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $jobs = array_map(
            fn (array $item) => ClaimedJob::fromArray($item),
            $data['jobs'] ?? [],
        );

        return new self(jobs: $jobs);
    }
}

/**
 * Batch job status.
 */
final readonly class BatchJobStatus
{
    public function __construct(
        public string $id,
        public string $status,
        public string $queueName,
        public int $retryCount,
        public string $createdAt,
        public ?string $completedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            queueName: (string) ($data['queueName'] ?? $data['queue'] ?? ''),
            retryCount: (int) ($data['retryCount'] ?? $data['retry_count'] ?? 0),
            createdAt: (string) ($data['createdAt'] ?? ''),
            completedAt: isset($data['completedAt']) ? (string) $data['completedAt'] : null,
        );
    }
}

/**
 * Batch status response.
 */
final readonly class BatchStatusResponse
{
    /**
     * @param array<BatchJobStatus> $statuses
     */
    public function __construct(
        public array $statuses,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // API might return array directly or wrapped in 'statuses'
        $items = $data['statuses'] ?? $data;
        if (!is_array($items) || (isset($items[0]) && !is_array($items[0]))) {
            $items = [];
        }

        $statuses = array_map(
            fn (array $item) => BatchJobStatus::fromArray($item),
            $items,
        );

        return new self(statuses: $statuses);
    }
}

/**
 * Bulk enqueue response.
 */
final readonly class BulkEnqueueResponse
{
    /**
     * @param array<array{index: int, jobId: string, created: bool}> $succeeded
     * @param array<array{index: int, error: string}> $failed
     */
    public function __construct(
        public array $succeeded,
        public array $failed,
        public int $total,
        public int $successCount,
        public int $failureCount,
        /** Alias for successCount */
        public int $created,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $succeeded = $data['succeeded'] ?? [];
        $failed = $data['failed'] ?? [];
        $successCount = (int) ($data['successCount'] ?? $data['success_count'] ?? count($succeeded));

        return new self(
            succeeded: $succeeded,
            failed: $failed,
            total: (int) ($data['total'] ?? $successCount + count($failed)),
            successCount: $successCount,
            failureCount: (int) ($data['failureCount'] ?? $data['failure_count'] ?? count($failed)),
            created: $successCount,
        );
    }
}

/**
 * Generic success response.
 */
final readonly class SuccessResponse
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? true),
            message: isset($data['message']) ? (string) $data['message'] : null,
        );
    }
}
