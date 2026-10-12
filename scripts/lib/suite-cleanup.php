<?php

declare(strict_types=1);

/**
 * Run prefix and clean-up helpers of scripts/test-local.php.
 *
 * Everything the suite creates is named after its run prefix
 * (`test-<unix time>-<8 hex>`, see generateTestId()): queues, schedules and
 * workflows. The clean-ups below touch only objects with such a name, so the
 * suite can run against an organization that also holds real queues,
 * schedules and workflows - including ones whose names merely start with
 * `test-`.
 *
 * Kept in their own file, without output and without side effects on load, so
 * tests/Unit/Scripts can exercise them against a mocked API.
 */

use Spooled\SpooledClient;

/**
 * A new run prefix: `test-<unix time>-<8 hex>`.
 */
function generateTestId(): string
{
    return 'test-' . time() . '-' . substr(md5((string) mt_rand()), 0, 8);
}

/**
 * Whether a queue, schedule or workflow name was made by a run of this suite,
 * i.e. starts with a run prefix from generateTestId() followed by `-`.
 */
function isSuiteOwnedName(?string $name): bool
{
    return $name !== null && preg_match('/^test-\d+-[0-9a-f]{8}-/', $name) === 1;
}

/**
 * Cancel pending, processing and scheduled jobs in queues of earlier runs of
 * this suite. Jobs in any other queue are never touched.
 *
 * @return int The number of jobs cancelled
 */
function cancelSuiteJobs(SpooledClient $client): int
{
    $active = ['pending', 'processing', 'scheduled'];
    $jobs = $client->jobs->list(['limit' => 100, 'status' => $active, 'q' => 'test-']);
    $cancelled = 0;

    foreach ($jobs->jobs as $job) {
        if (isSuiteOwnedName($job->queueName) && in_array($job->status, $active, true)) {
            try {
                $client->jobs->cancel($job->id);
                $cancelled++;
            } catch (\Throwable $e) {
                // Ignore errors - job might have completed or been deleted
            }
        }
    }

    return $cancelled;
}

/**
 * Cancel unfinished workflows of earlier runs of this suite (by workflow
 * name). Other workflows of the organization are never touched.
 *
 * @return int The number of workflows cancelled
 */
function cleanupWorkflows(SpooledClient $client): int
{
    $cancelled = 0;

    try {
        $workflows = $client->workflows->list(['limit' => 100]);
        foreach ($workflows->workflows as $workflow) {
            if (!isSuiteOwnedName($workflow->name)
                || in_array($workflow->status, ['completed', 'failed', 'cancelled'], true)) {
                continue;
            }

            try {
                $client->workflows->cancel($workflow->id);
                $cancelled++;
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    } catch (\Throwable $e) {
        // Ignore
    }

    return $cancelled;
}

/**
 * Delete schedules of earlier runs of this suite: both the schedule name and
 * its queue must carry a run prefix. Other schedules of the organization are
 * never touched.
 *
 * @return int The number of schedules deleted
 */
function cleanupSchedules(SpooledClient $client): int
{
    $deleted = 0;

    try {
        $schedules = $client->schedules->list(['limit' => 100]);
        foreach ($schedules->schedules as $schedule) {
            if (!isSuiteOwnedName($schedule->name) || !isSuiteOwnedName($schedule->queue)) {
                continue;
            }

            try {
                $client->schedules->delete($schedule->id);
                $deleted++;
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    } catch (\Throwable $e) {
        // Ignore
    }

    return $deleted;
}
