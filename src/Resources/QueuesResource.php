<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\Queue;
use Spooled\Types\QueueList;
use Spooled\Types\QueueStats;
use Spooled\Types\SuccessResponse;

/**
 * Queues resource for managing queues.
 */
final class QueuesResource extends BaseResource
{
    /**
     * List all queues.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): QueueList
    {
        $response = $this->httpClient->get('queues', $params);

        return QueueList::fromArray($response);
    }

    /**
     * Get a queue by name.
     */
    public function get(string $name): Queue
    {
        $response = $this->httpClient->get("queues/{$name}");

        return Queue::fromArray($response);
    }

    /**
     * Update queue configuration.
     *
     * @param array<string, mixed> $config
     */
    public function updateConfig(string $name, array $config): Queue
    {
        $response = $this->httpClient->put("queues/{$name}/config", $config);

        return Queue::fromArray($response);
    }

    /**
     * Get queue statistics.
     */
    public function getStats(string $name): QueueStats
    {
        $response = $this->httpClient->get("queues/{$name}/stats");

        return QueueStats::fromArray($response);
    }

    /**
     * Pause a queue.
     *
     * @param array<string, mixed> $options Options like 'reason' for pausing
     */
    public function pause(string $name, array $options = []): Queue
    {
        // Ensure we send an object, not an array (for Rust serde deserialization)
        $body = empty($options) ? ['reason' => null] : $options;
        $response = $this->httpClient->post("queues/{$name}/pause", $body);

        return Queue::fromArray($response);
    }

    /**
     * Resume a paused queue.
     */
    public function resume(string $name): Queue
    {
        // Resume endpoint doesn't require a body
        $response = $this->httpClient->post("queues/{$name}/resume");

        return Queue::fromArray($response);
    }

    /**
     * Delete a queue configuration.
     *
     * Without `$deleteJobs` this 409s while pending/processing jobs exist.
     * With `$deleteJobs` the API also deletes every job in the queue.
     */
    public function delete(string $name, bool $deleteJobs = false): SuccessResponse
    {
        $query = $deleteJobs ? ['delete_jobs' => 'true'] : [];
        $response = $this->httpClient->delete("queues/{$name}", $query);

        return SuccessResponse::fromArray($response);
    }

    /**
     * Delete a queue and all of its jobs.
     *
     * There is no `POST /queues/{name}/purge`. The backend contract is
     * `DELETE /queues/{name}?delete_jobs=true`.
     */
    public function purge(string $name): SuccessResponse
    {
        return $this->delete($name, true);
    }
}
