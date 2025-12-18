<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\HealthStatus;
use Throwable;

/**
 * Health resource for health checks.
 */
final class HealthResource extends BaseResource
{
    /**
     * Get general health status.
     */
    public function check(): HealthStatus
    {
        $response = $this->httpClient->get('health', skipApiPrefix: true);

        return HealthStatus::fromArray($response);
    }

    /**
     * Get liveness probe status.
     */
    public function live(): HealthStatus
    {
        $response = $this->httpClient->get('health/live', skipApiPrefix: true);

        return HealthStatus::fromArray($response);
    }

    /**
     * Get readiness probe status.
     */
    public function ready(): HealthStatus
    {
        $response = $this->httpClient->get('health/ready', skipApiPrefix: true);

        return HealthStatus::fromArray($response);
    }

    /**
     * Check if service is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $status = $this->check();

            return $status->isHealthy();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if service is live.
     */
    public function isLive(): bool
    {
        try {
            $status = $this->live();

            return $status->isHealthy();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if service is ready.
     */
    public function isReady(): bool
    {
        try {
            $status = $this->ready();

            return $status->isHealthy();
        } catch (Throwable) {
            return false;
        }
    }
}
