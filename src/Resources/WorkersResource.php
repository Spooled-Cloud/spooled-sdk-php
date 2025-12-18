<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\Worker;
use Spooled\Types\WorkerList;

/**
 * Workers resource for managing workers.
 */
final class WorkersResource extends BaseResource
{
    /**
     * List all workers.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): WorkerList
    {
        $response = $this->httpClient->get('workers', $params);

        return WorkerList::fromArray($response);
    }

    /**
     * Register a new worker.
     *
     * @param array<string, mixed> $params
     * @return Worker Worker with heartbeatIntervalSecs field
     */
    public function register(array $params): Worker
    {
        $response = $this->httpClient->post('workers/register', $params);

        return Worker::fromArray($response);
    }

    /**
     * Get a worker by ID.
     */
    public function get(string $workerId): Worker
    {
        $response = $this->httpClient->get("workers/{$workerId}");

        return Worker::fromArray($response);
    }

    /**
     * Send heartbeat for a worker.
     *
     * @param array<string, mixed> $params Optional stats to include
     */
    public function heartbeat(string $workerId, array $params = []): Worker
    {
        $response = $this->httpClient->post("workers/{$workerId}/heartbeat", $params);

        return Worker::fromArray($response);
    }

    /**
     * Deregister a worker.
     */
    public function deregister(string $workerId): void
    {
        $this->httpClient->post("workers/{$workerId}/deregister");
    }

    /**
     * Update worker status.
     *
     * @param array<string, mixed> $params
     */
    public function update(string $workerId, array $params): Worker
    {
        $response = $this->httpClient->put("workers/{$workerId}", $params);

        return Worker::fromArray($response);
    }
}
