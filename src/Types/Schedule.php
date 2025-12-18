<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Represents a schedule.
 */
final readonly class Schedule
{
    public function __construct(
        public string $id,
        public string $name,
        public string $queue,
        public string $schedule,
        public bool $paused,
        /** @var array<string, mixed> */
        public array $payload,
        public ?string $organizationId,
        public ?string $timezone,
        public int $priority,
        public int $maxRetries,
        public ?string $description,
        /** @var array<string, mixed>|null */
        public ?array $metadata,
        public ?string $lastRunAt,
        public ?string $nextRunAt,
        public int $runCount,
        public int $failedCount,
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
        // Handle is_active (backend) vs paused (SDK convention)
        // is_active=true means NOT paused, is_active=false means paused
        $isActive = $data['is_active'] ?? $data['isActive'] ?? null;
        $paused = isset($isActive) ? !$isActive : (bool) ($data['paused'] ?? false);

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            queue: (string) ($data['queue'] ?? $data['queue_name'] ?? $data['queueName'] ?? ''),
            schedule: (string) ($data['schedule'] ?? $data['cron_expression'] ?? $data['cronExpression'] ?? ''),
            paused: $paused,
            payload: is_array($data['payload'] ?? $data['payload_template'] ?? $data['payloadTemplate'] ?? null)
                ? ($data['payload'] ?? $data['payload_template'] ?? $data['payloadTemplate'])
                : [],
            organizationId: isset($data['organization_id']) ? (string) $data['organization_id']
                : (isset($data['organizationId']) ? (string) $data['organizationId'] : null),
            timezone: isset($data['timezone']) ? (string) $data['timezone'] : null,
            priority: (int) ($data['priority'] ?? 0),
            maxRetries: (int) ($data['max_retries'] ?? $data['maxRetries'] ?? 3),
            description: isset($data['description']) ? (string) $data['description'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            lastRunAt: isset($data['last_run_at']) ? (string) $data['last_run_at']
                : (isset($data['lastRunAt']) ? (string) $data['lastRunAt'] : null),
            nextRunAt: isset($data['next_run_at']) ? (string) $data['next_run_at']
                : (isset($data['nextRunAt']) ? (string) $data['nextRunAt'] : null),
            runCount: (int) ($data['run_count'] ?? $data['runCount'] ?? 0),
            failedCount: (int) ($data['failed_count'] ?? $data['failedCount'] ?? 0),
            createdAt: isset($data['created_at']) ? (string) $data['created_at']
                : (isset($data['createdAt']) ? (string) $data['createdAt'] : null),
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at']
                : (isset($data['updatedAt']) ? (string) $data['updatedAt'] : null),
        );
    }
}

/**
 * Schedule list response.
 */
final readonly class ScheduleList
{
    /**
     * @param array<Schedule> $schedules
     */
    public function __construct(
        public array $schedules,
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
        $schedules = array_map(
            fn (array $item) => Schedule::fromArray($item),
            $data['schedules'] ?? $data['data'] ?? [],
        );

        return new self(
            schedules: $schedules,
            total: (int) ($data['total'] ?? count($schedules)),
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['pageSize'] ?? $data['limit'] ?? count($schedules)),
        );
    }
}

/**
 * Schedule history entry.
 */
final readonly class ScheduleHistoryEntry
{
    public function __construct(
        public string $id,
        public string $scheduleId,
        public string $status,
        public ?string $jobId,
        public ?string $error,
        public ?string $executedAt,
        public ?float $duration,
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
            scheduleId: (string) ($data['scheduleId'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            jobId: isset($data['jobId']) ? (string) $data['jobId'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            executedAt: isset($data['executedAt']) ? (string) $data['executedAt'] : null,
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
        );
    }
}
