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
        return new self(
            name: (string) ($data['name'] ?? ''),
            paused: (bool) ($data['paused'] ?? false),
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            maxConcurrency: isset($data['maxConcurrency']) ? (int) $data['maxConcurrency'] : null,
            maxRetries: isset($data['maxRetries']) ? (int) $data['maxRetries'] : null,
            retryDelay: isset($data['retryDelay']) ? (int) $data['retryDelay'] : null,
            timeout: isset($data['timeout']) ? (int) $data['timeout'] : null,
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
        $queues = array_map(
            fn (array $item) => Queue::fromArray($item),
            $data['queues'] ?? $data['data'] ?? [],
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
            name: (string) ($data['name'] ?? $data['queueName'] ?? ''),
            pending: (int) ($data['pending'] ?? 0),
            claimed: (int) ($data['claimed'] ?? 0),
            completed: (int) ($data['completed'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
            cancelled: (int) ($data['cancelled'] ?? 0),
            scheduled: (int) ($data['scheduled'] ?? 0),
            total: (int) ($data['total'] ?? 0),
            activeWorkers: (int) ($data['activeWorkers'] ?? 0),
            avgProcessingTime: (float) ($data['avgProcessingTime'] ?? 0.0),
            throughput: (float) ($data['throughput'] ?? 0.0),
        );
    }
}
