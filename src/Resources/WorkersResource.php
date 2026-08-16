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
     * Accepted keys: `queueName`, `hostname`, `maxConcurrency`, `workerType`,
     * `version`, `metadata`, and the optional `workerId`.
     *
     * `workerId` is 1-128 characters from `[A-Za-z0-9._-]`. Supplying a stable
     * value (a hostname or an ordinal, never a PID or a fresh UUID) makes
     * registration an upsert, so a restarting worker reuses the row it already
     * owns; re-registering an id you own is not charged against the plan worker
     * cap. Omit it and the server mints a UUID instead, which means every
     * restart leaves the previous row holding a worker-cap slot until the
     * stale-worker reaper clears it about two minutes later - enough for a
     * crash-looping worker on a tight plan to quota itself out of registering.
     * An id owned by a different organization is rejected with HTTP 409
     * (`Spooled\Errors\ConflictError`).
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
     * Backend returns an empty 200 body; there is no worker payload to decode.
     *
     * @param array<string, mixed> $params Optional stats to include
     */
    public function heartbeat(string $workerId, array $params = []): void
    {
        $this->httpClient->post("workers/{$workerId}/heartbeat", $params);
    }

    /**
     * Deregister a worker.
     */
    public function deregister(string $workerId): void
    {
        $this->httpClient->post("workers/{$workerId}/deregister");
    }
}
