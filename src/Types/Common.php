<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Health check response.
 */
final readonly class HealthStatus
{
    public function __construct(
        public string $status,
        public ?string $version,
        public ?float $uptime,
        /** @var array<string, mixed>|null */
        public ?array $checks,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // If response is empty but we got here (no error), assume healthy
        // This handles /health/live and /health/ready which return 200 with empty body
        $status = (string) ($data['status'] ?? (empty($data) ? 'healthy' : 'unknown'));

        $checks = is_array($data['checks'] ?? null) ? $data['checks'] : null;
        // GET /health sends database/cache booleans, not a checks object.
        if ($checks === null && (array_key_exists('database', $data) || array_key_exists('cache', $data))) {
            $checks = [
                'database' => (bool) ($data['database'] ?? false),
                'cache' => (bool) ($data['cache'] ?? false),
            ];
        }

        return new self(
            status: $status,
            version: isset($data['version']) ? (string) $data['version'] : null,
            uptime: isset($data['uptime']) ? (float) $data['uptime'] : (isset($data['uptimeSeconds']) ? (float) $data['uptimeSeconds'] : null),
            checks: $checks,
        );
    }

    /**
     * Check if the service is healthy.
     */
    public function isHealthy(): bool
    {
        return $this->status === 'healthy' || $this->status === 'ok';
    }
}

/**
 * Dashboard statistics.
 */
final readonly class DashboardStats
{
    public function __construct(
        public int $totalJobs,
        public int $pendingJobs,
        public int $processingJobs,
        public int $completedJobs,
        public int $failedJobs,
        public int $deadLetterJobs,
        public int $totalWorkers,
        public int $healthyWorkers,
        /** @var array<array<string, mixed>> */
        public array $queues,
        /** @var array<string, mixed> */
        public array $system,
        /** @var array<string, mixed> */
        public array $recentActivity,
        public float $avgWaitTimeMs,
        public float $avgProcessingTimeMs,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $jobs = is_array($data['jobs'] ?? null) ? $data['jobs'] : [];
        $workers = is_array($data['workers'] ?? null) ? $data['workers'] : [];
        $system = $data['system'] ?? [];
        $recentActivity = $data['recentActivity'] ?? $data['recent_activity'] ?? [];

        return new self(
            totalJobs: (int) ($jobs['total'] ?? $data['totalJobs'] ?? 0),
            pendingJobs: (int) ($jobs['pending'] ?? $data['pendingJobs'] ?? 0),
            processingJobs: (int) ($jobs['processing'] ?? $data['processingJobs'] ?? 0),
            // GET /dashboard JobSummaryStats is completed_24h / failed_24h
            // (camelCased completed24h / failed24h), not completed/failed.
            completedJobs: (int) ($jobs['completed24h'] ?? $jobs['completed_24h'] ?? $data['completedJobs'] ?? 0),
            failedJobs: (int) ($jobs['failed24h'] ?? $jobs['failed_24h'] ?? $data['failedJobs'] ?? 0),
            deadLetterJobs: (int) ($jobs['deadletter'] ?? $data['deadLetterJobs'] ?? 0),
            totalWorkers: (int) ($workers['total'] ?? $data['totalWorkers'] ?? 0),
            healthyWorkers: (int) ($workers['healthy'] ?? $data['healthyWorkers'] ?? 0),
            queues: is_array($data['queues'] ?? null) ? $data['queues'] : [],
            system: is_array($system) ? $system : [],
            recentActivity: is_array($recentActivity) ? $recentActivity : [],
            avgWaitTimeMs: (float) ($jobs['avgWaitTimeMs'] ?? $jobs['avg_wait_time_ms'] ?? $data['avgWaitTimeMs'] ?? 0.0),
            avgProcessingTimeMs: (float) ($jobs['avgProcessingTimeMs'] ?? $jobs['avg_processing_time_ms'] ?? $data['avgProcessingTimeMs'] ?? 0.0),
        );
    }

    /**
     * Get the total number of active workers (alias for healthyWorkers).
     */
    public function getActiveWorkers(): int
    {
        return $this->healthyWorkers;
    }

    /**
     * Get the number of active queues.
     */
    public function getActiveQueues(): int
    {
        return count($this->queues);
    }
}

/**
 * Pagination parameters.
 */
final readonly class PaginationParams
{
    public function __construct(
        public int $page = 1,
        public int $pageSize = 20,
        public ?string $sortBy = null,
        public ?string $sortOrder = null,
    ) {
    }

    /**
     * Convert to query parameters.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'sortBy' => $this->sortBy,
            'sortOrder' => $this->sortOrder,
        ], fn ($v) => $v !== null);
    }
}
