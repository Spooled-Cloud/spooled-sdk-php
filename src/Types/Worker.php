<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Worker status values returned by the REST API.
 */
enum WorkerStatus: string
{
    case HEALTHY = 'healthy';
    case DEGRADED = 'degraded';
    case OFFLINE = 'offline';
    case DRAINING = 'draining';
}

/**
 * Worker detail or registration response from the REST API.
 *
 * Detail fields match the backend public JSON names (camelCased by the HTTP client).
 * Registration responses only populate id, queueName, leaseDurationSecs, and
 * heartbeatIntervalSecs; other fields use empty defaults.
 */
final readonly class Worker
{
    /**
     * @param list<string> $queueNames
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $id,
        public string $queueName,
        public array $queueNames,
        public string $hostname,
        public ?string $workerType,
        public int $maxConcurrency,
        public int $currentJobs,
        public string $status,
        public ?string $lastHeartbeat,
        public ?array $metadata,
        public ?string $version,
        public ?string $organizationId,
        public ?string $registeredAt,
        public ?string $updatedAt,
        public int $leaseDurationSecs = 0,
        public int $heartbeatIntervalSecs = 0,
    ) {
    }

    /**
     * Create from API response array (camelCase keys after HTTP client conversion).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $queueName = (string) ($data['queueName'] ?? $data['queue_name'] ?? '');
        $queueNames = self::stringList(
            $data['queueNames'] ?? $data['queue_names'] ?? $data['queues'] ?? null,
        );
        if ($queueNames === [] && $queueName !== '') {
            $queueNames = [$queueName];
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            queueName: $queueName,
            queueNames: $queueNames,
            hostname: (string) ($data['hostname'] ?? $data['name'] ?? ''),
            workerType: self::optionalString($data['workerType'] ?? $data['worker_type'] ?? null),
            maxConcurrency: (int) (
                $data['maxConcurrency']
                ?? $data['max_concurrency']
                ?? $data['concurrency']
                ?? 0
            ),
            currentJobs: (int) (
                $data['currentJobs']
                ?? $data['current_jobs']
                ?? $data['activeJobs']
                ?? 0
            ),
            status: (string) ($data['status'] ?? 'healthy'),
            lastHeartbeat: self::optionalString(
                $data['lastHeartbeat'] ?? $data['last_heartbeat'] ?? null,
            ),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            version: self::optionalString($data['version'] ?? null),
            organizationId: self::optionalString(
                $data['organizationId'] ?? $data['organization_id'] ?? null,
            ),
            registeredAt: self::optionalString(
                $data['registeredAt']
                ?? $data['registered_at']
                ?? $data['createdAt']
                ?? $data['created_at']
                ?? null,
            ),
            updatedAt: self::optionalString($data['updatedAt'] ?? $data['updated_at'] ?? null),
            leaseDurationSecs: (int) (
                $data['leaseDurationSecs'] ?? $data['lease_duration_secs'] ?? 0
            ),
            heartbeatIntervalSecs: (int) (
                $data['heartbeatIntervalSecs'] ?? $data['heartbeat_interval_secs'] ?? 0
            ),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
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
        // Handle both wrapped and raw array responses.
        // Wrapped: { "workers": [...], "total": 10 }
        // Raw: [ {...}, {...}, ... ] (the shape GET /api/v1/workers returns)
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $workersData = $isRawArray ? $data : ($data['workers'] ?? $data['data'] ?? []);

        $workers = array_map(
            fn (array $item) => Worker::fromArray($item),
            $workersData,
        );

        return new self(
            workers: $workers,
            total: (int) ($data['total'] ?? count($workers)),
        );
    }
}
