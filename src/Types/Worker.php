<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Worker status enumeration.
 */
enum WorkerStatus: string
{
    case ACTIVE = 'active';
    case IDLE = 'idle';
    case OFFLINE = 'offline';
}

/**
 * Represents a worker.
 */
final readonly class Worker
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        /** @var array<string> */
        public array $queues,
        public int $concurrency,
        /** Heartbeat interval in seconds (from registration response) */
        public int $heartbeatIntervalSecs,
        public ?string $organizationId,
        public ?string $hostname,
        public ?int $pid,
        public ?string $version,
        public ?string $workerType,
        public int $activeJobs,
        public int $completedJobs,
        public int $failedJobs,
        public ?string $lastHeartbeat,
        public ?string $registeredAt,
        public ?string $createdAt,
        /** @var array<string, mixed>|null */
        public ?array $metadata,
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
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            status: (string) ($data['status'] ?? 'idle'),
            queues: is_array($data['queues'] ?? null) ? $data['queues'] : [],
            concurrency: (int) ($data['concurrency'] ?? $data['maxConcurrency'] ?? 1),
            heartbeatIntervalSecs: (int) ($data['heartbeatIntervalSecs'] ?? $data['heartbeat_interval_secs'] ?? 30),
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            hostname: isset($data['hostname']) ? (string) $data['hostname'] : null,
            pid: isset($data['pid']) ? (int) $data['pid'] : null,
            version: isset($data['version']) ? (string) $data['version'] : null,
            workerType: isset($data['workerType']) ? (string) $data['workerType'] : null,
            activeJobs: (int) ($data['activeJobs'] ?? $data['currentJobs'] ?? 0),
            completedJobs: (int) ($data['completedJobs'] ?? 0),
            failedJobs: (int) ($data['failedJobs'] ?? 0),
            lastHeartbeat: isset($data['lastHeartbeat']) ? (string) $data['lastHeartbeat'] : null,
            registeredAt: isset($data['registeredAt']) ? (string) $data['registeredAt'] : null,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        );
    }
}

/**
 * Worker list response.
 */
final readonly class WorkerList
{
    /**
     * @param array<Worker> $workers
     */
    public function __construct(
        public array $workers,
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
        $workers = array_map(
            fn (array $item) => Worker::fromArray($item),
            $data['workers'] ?? $data['data'] ?? [],
        );

        return new self(
            workers: $workers,
            total: (int) ($data['total'] ?? count($workers)),
        );
    }
}
