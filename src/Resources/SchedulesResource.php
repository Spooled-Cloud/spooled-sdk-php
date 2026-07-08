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
        $params = $this->mapWriteParams($params);

        $response = $this->httpClient->post('schedules', $params);

        // The create endpoint returns a partial object ({id, name,
        // cron_expression, next_run_at}); it never echoes timezone, queue_name
        // or payload_template. Backfill the fields the caller supplied so
        // create() returns a Schedule consistent with a subsequent get().
        $response = $this->applyCreateFallbacks($response, $params);

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
        $params = $this->mapWriteParams($params);

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

    /**
     * Map the SDK's documented parameter aliases to the field names the API
     * expects, mirroring JobsResource::create(). The HTTP layer snake-cases the
     * canonical camelCase names before sending (queueName -> queue_name,
     * cronExpression -> cron_expression, payloadTemplate -> payload_template).
     *
     * Documented aliases (see the README "Schedules" example): `queue`,
     * `schedule` (or `cron`) and `payload`. Callers may also pass the canonical
     * names directly (`queueName`/`cronExpression`/`payloadTemplate`); those are
     * left untouched so existing callers keep working.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mapWriteParams(array $params): array
    {
        // 'queue' -> 'queueName'
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
            unset($params['queue']);
        }

        // 'schedule' / 'cron' -> 'cronExpression'
        if (isset($params['schedule']) && !isset($params['cronExpression'])) {
            $params['cronExpression'] = $params['schedule'];
            unset($params['schedule']);
        }
        if (isset($params['cron']) && !isset($params['cronExpression'])) {
            $params['cronExpression'] = $params['cron'];
            unset($params['cron']);
        }

        // 'payload' -> 'payloadTemplate'
        if (isset($params['payload']) && !isset($params['payloadTemplate'])) {
            $params['payloadTemplate'] = $params['payload'];
            unset($params['payload']);
        }

        return $params;
    }

    /**
     * Backfill fields the create endpoint omits from its response using the
     * (already alias-mapped) request params, so create() returns a Schedule that
     * matches a subsequent get(). Response values always win; params are only a
     * fallback for keys the response does not provide.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function applyCreateFallbacks(array $response, array $params): array
    {
        foreach (['queueName', 'payloadTemplate', 'priority', 'maxRetries', 'description', 'metadata'] as $key) {
            if (!array_key_exists($key, $response) && array_key_exists($key, $params)) {
                $response[$key] = $params[$key];
            }
        }

        // Timezone is never present in the create response. Mirror get() by
        // using the requested value, falling back to the backend default 'UTC'.
        if (!isset($response['timezone'])) {
            $response['timezone'] = $params['timezone'] ?? 'UTC';
        }

        return $response;
    }
}
