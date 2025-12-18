<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Workflow status enumeration.
 */
enum WorkflowStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}

/**
 * Represents a workflow.
 */
final readonly class Workflow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?string $organizationId,
        public ?string $description,
        /** @var array<string, mixed>|null */
        public ?array $metadata,
        public int $totalJobs,
        public int $completedJobs,
        public int $failedJobs,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $startedAt,
        public ?string $completedAt,
        /** @var array<array{key: string, jobId: string}>|null Job IDs from creation response */
        public ?array $jobs = null,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Handle create response format (workflowId/workflow_id) vs get response format (id)
        $id = (string) ($data['id'] ?? $data['workflowId'] ?? $data['workflow_id'] ?? '');

        // Parse jobIds from creation response (camelCase from SDK's response conversion)
        $jobs = null;
        $jobIds = $data['jobIds'] ?? $data['job_ids'] ?? null;
        if (is_array($jobIds)) {
            $jobs = array_map(fn ($j) => [
                'key' => (string) ($j['key'] ?? ''),
                'jobId' => (string) ($j['jobId'] ?? $j['job_id'] ?? ''),
            ], $jobIds);
        }

        return new self(
            id: $id,
            name: (string) ($data['name'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            organizationId: isset($data['organization_id']) ? (string) $data['organization_id']
                : (isset($data['organizationId']) ? (string) $data['organizationId'] : null),
            description: isset($data['description']) ? (string) $data['description'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            totalJobs: (int) ($data['total_jobs'] ?? $data['totalJobs'] ?? 0),
            completedJobs: (int) ($data['completed_jobs'] ?? $data['completedJobs'] ?? 0),
            failedJobs: (int) ($data['failed_jobs'] ?? $data['failedJobs'] ?? 0),
            createdAt: isset($data['created_at']) ? (string) $data['created_at']
                : (isset($data['createdAt']) ? (string) $data['createdAt'] : null),
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at']
                : (isset($data['updatedAt']) ? (string) $data['updatedAt'] : null),
            startedAt: isset($data['started_at']) ? (string) $data['started_at']
                : (isset($data['startedAt']) ? (string) $data['startedAt'] : null),
            completedAt: isset($data['completed_at']) ? (string) $data['completed_at']
                : (isset($data['completedAt']) ? (string) $data['completedAt'] : null),
            jobs: $jobs,
        );
    }
}

/**
 * Workflow list response.
 */
final readonly class WorkflowList
{
    /**
     * @param array<Workflow> $workflows
     */
    public function __construct(
        public array $workflows,
        public int $total,
        public int $page,
        public int $pageSize,
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
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $workflowsData = $isRawArray ? $data : ($data['workflows'] ?? $data['data'] ?? []);

        $workflows = array_map(
            fn (array $item) => Workflow::fromArray($item),
            $workflowsData,
        );

        return new self(
            workflows: $workflows,
            total: (int) ($data['total'] ?? count($workflows)),
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['pageSize'] ?? $data['limit'] ?? count($workflows)),
        );
    }
}

/**
 * Workflow job definition for creation.
 */
final readonly class WorkflowJobDefinition
{
    public function __construct(
        public string $name,
        public string $queue,
        /** @var array<string, mixed> */
        public array $payload,
        /** @var array<string>|null */
        public ?array $dependencies = null,
        public int $priority = 0,
        public int $maxRetries = 3,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
    ) {
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'queue' => $this->queue,
            'payload' => $this->payload,
            'dependencies' => $this->dependencies,
            'priority' => $this->priority,
            'maxRetries' => $this->maxRetries,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null);
    }
}

/**
 * Workflow job (job within a workflow).
 */
final readonly class WorkflowJob
{
    public function __construct(
        public string $id,
        public string $workflowId,
        public string $key,
        public string $status,
        public string $queueName,
        /** @var array<string> */
        public array $dependsOn,
        public ?string $error,
        /** @var array<string, mixed>|null */
        public ?array $result,
        /** @var array<string, mixed>|null */
        public ?array $payload,
        public int $priority,
        public int $retryCount,
        public int $maxRetries,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $completedAt,
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
            id: (string) ($data['id'] ?? $data['jobId'] ?? ''),
            workflowId: (string) ($data['workflowId'] ?? ''),
            key: (string) ($data['key'] ?? $data['name'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            queueName: (string) ($data['queueName'] ?? $data['queue'] ?? ''),
            dependsOn: is_array($data['dependsOn'] ?? $data['dependencies'] ?? null)
                ? ($data['dependsOn'] ?? $data['dependencies'])
                : [],
            error: isset($data['error']) ? (string) $data['error'] : null,
            result: is_array($data['result'] ?? null) ? $data['result'] : null,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : null,
            priority: (int) ($data['priority'] ?? 0),
            retryCount: (int) ($data['retryCount'] ?? 0),
            maxRetries: (int) ($data['maxRetries'] ?? 3),
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            startedAt: isset($data['startedAt']) ? (string) $data['startedAt'] : null,
            completedAt: isset($data['completedAt']) ? (string) $data['completedAt'] : null,
        );
    }
}

/**
 * Workflow job status summary.
 */
final readonly class WorkflowJobStatus
{
    public function __construct(
        public string $jobId,
        public string $key,
        public string $status,
        public int $retryCount,
        public ?string $error,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['jobId'] ?? $data['id'] ?? ''),
            key: (string) ($data['key'] ?? $data['name'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            retryCount: (int) ($data['retryCount'] ?? 0),
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}

/**
 * Job with its dependencies.
 */
final readonly class JobWithDependencies
{
    /**
     * @param array<JobDependency> $dependencies
     */
    public function __construct(
        public string $jobId,
        public array $dependencies,
        public string $dependencyMode,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $deps = array_map(
            fn (array $item) => JobDependency::fromArray($item),
            $data['dependencies'] ?? [],
        );

        return new self(
            jobId: (string) ($data['jobId'] ?? $data['id'] ?? ''),
            dependencies: $deps,
            dependencyMode: (string) ($data['dependencyMode'] ?? 'all'),
        );
    }
}

/**
 * Job dependency info.
 */
final readonly class JobDependency
{
    public function __construct(
        public string $jobId,
        public string $status,
        public bool $isMet,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) ($data['jobId'] ?? $data['id'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            isMet: (bool) ($data['isMet'] ?? $data['met'] ?? false),
        );
    }
}
