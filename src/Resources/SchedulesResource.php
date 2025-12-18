<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\Schedule;
use Spooled\Types\ScheduleHistoryEntry;
use Spooled\Types\ScheduleList;
use Spooled\Types\SuccessResponse;

/**
 * Schedules resource for managing scheduled jobs.
 */
final class SchedulesResource extends BaseResource
{
    /**
     * List all schedules.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ScheduleList
    {
        $response = $this->httpClient->get('schedules', $params);

        return ScheduleList::fromArray($response);
    }

    /**
     * Create a new schedule.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params): Schedule
    {
        $response = $this->httpClient->post('schedules', $params);

        return Schedule::fromArray($response);
    }

    /**
     * Get a schedule by ID.
     */
    public function get(string $scheduleId): Schedule
    {
        $response = $this->httpClient->get("schedules/{$scheduleId}");

        return Schedule::fromArray($response);
    }

    /**
     * Update a schedule.
     *
     * @param array<string, mixed> $params
     */
    public function update(string $scheduleId, array $params): Schedule
    {
        $response = $this->httpClient->put("schedules/{$scheduleId}", $params);

        return Schedule::fromArray($response);
    }

    /**
     * Delete a schedule.
     */
    public function delete(string $scheduleId): SuccessResponse
    {
        $response = $this->httpClient->delete("schedules/{$scheduleId}");

        return SuccessResponse::fromArray($response);
    }

    /**
     * Pause a schedule.
     */
    public function pause(string $scheduleId): Schedule
    {
        $response = $this->httpClient->post("schedules/{$scheduleId}/pause");

        return Schedule::fromArray($response);
    }

    /**
     * Resume a paused schedule.
     */
    public function resume(string $scheduleId): Schedule
    {
        $response = $this->httpClient->post("schedules/{$scheduleId}/resume");

        return Schedule::fromArray($response);
    }

    /**
     * Trigger a schedule immediately.
     *
     * @return array{jobId: string, triggeredAt: string}
     */
    public function trigger(string $scheduleId): array
    {
        $response = $this->httpClient->post("schedules/{$scheduleId}/trigger");

        return [
            'jobId' => (string) ($response['job_id'] ?? $response['jobId'] ?? ''),
            'triggeredAt' => (string) ($response['triggered_at'] ?? $response['triggeredAt'] ?? ''),
        ];
    }

    /**
     * Get schedule execution history.
     *
     * @param array<string, mixed> $params
     * @return array<ScheduleHistoryEntry>
     */
    public function history(string $scheduleId, array $params = []): array
    {
        $response = $this->httpClient->get("schedules/{$scheduleId}/history", $params);
        $entries = $response['history'] ?? $response['data'] ?? [];

        return array_map(
            fn (array $item) => ScheduleHistoryEntry::fromArray($item),
            $entries,
        );
    }
}
