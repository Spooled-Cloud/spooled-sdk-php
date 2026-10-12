<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Event names an outgoing webhook can subscribe to.
 *
 * For building `events` on `webhooks->create()` / `update()`; plain strings
 * work the same (a case is sent as its value). Webhooks read from the API keep
 * `events` as strings, so a name added on the server later still parses.
 */
enum WebhookEvent: string
{
    case JOB_CREATED = 'job.created';
    case JOB_STARTED = 'job.started';
    case JOB_COMPLETED = 'job.completed';
    case JOB_FAILED = 'job.failed';
    case JOB_CANCELLED = 'job.cancelled';
    /** The job failed for good and moved to the dead-letter queue. */
    case JOB_DEADLETTERED = 'job.deadlettered';
    /** A failed job was scheduled for another attempt. */
    case JOB_RETRIED = 'job.retried';
    case QUEUE_PAUSED = 'queue.paused';
    case QUEUE_RESUMED = 'queue.resumed';
    case WORKER_REGISTERED = 'worker.registered';
    case WORKER_DEREGISTERED = 'worker.deregistered';
    case SCHEDULE_TRIGGERED = 'schedule.triggered';
    case WORKFLOW_COMPLETED = 'workflow.completed';
    case WORKFLOW_FAILED = 'workflow.failed';
}
