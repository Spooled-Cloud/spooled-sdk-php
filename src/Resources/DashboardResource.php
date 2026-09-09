<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\DashboardStats;

/**
 * Dashboard resource for dashboard statistics.
 */
final class DashboardResource extends BaseResource
{
    /**
     * Get dashboard statistics.
     *
     * @param array<string, mixed> $params
     */
    public function getStats(array $params = []): DashboardStats
    {
        $response = $this->httpClient->get('dashboard', $params);

        return DashboardStats::fromArray($response);
    }

    /**
     * Get dashboard overview.
     *
     * There is no GET /dashboard/overview. GET /dashboard is the only dashboard
     * document (system, jobs, queues, workers, recent_activity).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getOverview(array $params = []): array
    {
        return $this->dashboard($params);
    }

    /**
     * Get job summary counts from GET /dashboard (no charts route).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getJobCharts(array $params = []): array
    {
        return $this->dashboardSlice($params, 'jobs');
    }

    /**
     * Get 1h activity counts from GET /dashboard (no throughput charts route).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getThroughputCharts(array $params = []): array
    {
        return $this->dashboardSlice($params, 'recentActivity', 'recent_activity');
    }

    /**
     * Get worker summary counts from GET /dashboard (no charts route).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getWorkerCharts(array $params = []): array
    {
        return $this->dashboardSlice($params, 'workers');
    }

    /**
     * Get recent activity from GET /dashboard (no /dashboard/activity route).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getRecentActivity(array $params = []): array
    {
        return $this->dashboardSlice($params, 'recentActivity', 'recent_activity');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dashboard(array $params = []): array
    {
        $data = $this->httpClient->get('dashboard', $params);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dashboardSlice(array $params, string $camelKey, ?string $snakeKey = null): array
    {
        $data = $this->dashboard($params);
        $slice = $data[$camelKey] ?? ($snakeKey !== null ? ($data[$snakeKey] ?? null) : null);

        return is_array($slice) ? $slice : [];
    }
}
