<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Represents a queue.
 */
final readonly class Queue
{
    public function __construct(
        public string $name,
        public bool $paused,
        public ?string $organizationId,
        public ?int $maxConcurrency,
        public ?int $maxRetries,
        public ?int $retryDelay,
        public ?int $timeout,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $paused = array_key_exists('paused', $data)
            ? (bool) $data['paused']
            : (array_key_exists('enabled', $data) ? !(bool) $data['enabled'] : false);
        $timeout = $data['timeout'] ?? $data['defaultTimeout'] ?? $data['default_timeout'] ?? null;

        return new self(
            name: (string) ($data['queueName'] ?? $data['queue_name'] ?? $data['name'] ?? ''),
            paused: $paused,
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            maxConcurrency: isset($data['maxConcurrency']) ? (int) $data['maxConcurrency'] : null,
            maxRetries: isset($data['maxRetries']) ? (int) $data['maxRetries'] : null,
            retryDelay: isset($data['retryDelay']) ? (int) $data['retryDelay'] : null,
            timeout: $timeout !== null ? (int) $timeout : null,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) ? (string) $data['updatedAt'] : null,
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
            'name' => $this->name,
            'paused' => $this->paused,
            'organizationId' => $this->organizationId,
            'maxConcurrency' => $this->maxConcurrency,
            'maxRetries' => $this->maxRetries,
            'retryDelay' => $this->retryDelay,
            'timeout' => $this->timeout,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ], fn ($v) => $v !== null);
    }
}

/**
 * Queue list response.
 */
final readonly class QueueList
{
    /**
     * @param array<Queue> $queues
     */
    public function __construct(
        public array $queues,
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
        // Handle both wrapped and raw array responses.
        // Wrapped: { "queues": [...], "total": 10 }
        // Raw: [ {...}, {...}, ... ] (the shape GET /api/v1/queues returns)
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $queuesData = $isRawArray ? $data : ($data['queues'] ?? $data['data'] ?? []);

        $queues = array_map(
            fn (array $item) => Queue::fromArray($item),
            $queuesData,
        );

        return new self(
            queues: $queues,
            total: (int) ($data['total'] ?? count($queues)),
        );
    }
}

/**
 * Queue statistics.
 */
final readonly class QueueStats
{
    public function __construct(
        public string $name,
        public int $pending,
        public int $claimed,
        public int $completed,
        public int $failed,
        public int $cancelled,
        public int $scheduled,
        public int $total,
        public int $activeWorkers,
        public float $avgProcessingTime,
        public float $throughput,
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
            name: (string) ($data['queueName'] ?? $data['queue_name'] ?? $data['name'] ?? ''),
            pending: (int) ($data['pendingJobs'] ?? $data['pending_jobs'] ?? $data['pending'] ?? 0),
            claimed: (int) (
                $data['processingJobs']
                ?? $data['processing_jobs']
                ?? $data['claimed']
                ?? 0
            ),
            completed: (int) (
                $data['completedJobs24h']
                ?? $data['completed_jobs_24h']
                ?? $data['completed']
                ?? 0
            ),
            failed: (int) (
                $data['failedJobs24h']
                ?? $data['failed_jobs_24h']
                ?? $data['failed']
                ?? 0
            ),
            cancelled: (int) ($data['cancelled'] ?? 0),
            scheduled: (int) ($data['scheduled'] ?? 0),
            total: (int) ($data['total'] ?? 0),
            activeWorkers: (int) ($data['activeWorkers'] ?? $data['active_workers'] ?? 0),
            avgProcessingTime: (float) (
                $data['avgProcessingTimeMs']
                ?? $data['avg_processing_time_ms']
                ?? $data['avgProcessingTime']
                ?? 0.0
            ),
            throughput: (float) ($data['throughput'] ?? 0.0),
        );
    }
}
