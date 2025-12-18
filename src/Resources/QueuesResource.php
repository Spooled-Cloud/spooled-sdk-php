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
     * Delete a queue.
     */
    public function delete(string $name): SuccessResponse
    {
        $response = $this->httpClient->delete("queues/{$name}");

        return SuccessResponse::fromArray($response);
    }

    /**
     * Purge all jobs from a queue.
     */
    public function purge(string $name): SuccessResponse
    {
        $response = $this->httpClient->post("queues/{$name}/purge");

        return SuccessResponse::fromArray($response);
    }
}
