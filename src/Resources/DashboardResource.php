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
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getOverview(array $params = []): array
    {
        return $this->httpClient->get('dashboard/overview', $params);
    }

    /**
     * Get job charts data.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getJobCharts(array $params = []): array
    {
        return $this->httpClient->get('dashboard/charts/jobs', $params);
    }

    /**
     * Get throughput charts data.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getThroughputCharts(array $params = []): array
    {
        return $this->httpClient->get('dashboard/charts/throughput', $params);
    }

    /**
     * Get worker charts data.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getWorkerCharts(array $params = []): array
    {
        return $this->httpClient->get('dashboard/charts/workers', $params);
    }

    /**
     * Get recent activity.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getRecentActivity(array $params = []): array
    {
        return $this->httpClient->get('dashboard/activity', $params);
    }
}
