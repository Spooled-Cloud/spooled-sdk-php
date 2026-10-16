# Spooled PHP SDK

Official PHP SDK for [Spooled Cloud](https://spooled.cloud) - a modern job queue service for distributed applications.

[**Live Demo (SpriteForge)**](https://example.spooled.cloud) • [Documentation](https://spooled.cloud/docs)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spooled-cloud/spooled.svg)](https://packagist.org/packages/spooled-cloud/spooled)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)
[![CI](https://github.com/spooled-cloud/spooled-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/spooled-cloud/spooled-sdk-php/actions/workflows/ci.yml)

## Features

- **Full REST API support** - Jobs, queues, workers, schedules, workflows, webhooks, and more
- **Worker runtime** - Process jobs with lease renewal, lease fencing, events, and graceful shutdown
- **Realtime events** - SSE and WebSocket clients for live job/queue events
- **Optional gRPC transport** - High-performance binary protocol when extensions are available
- **Retry & circuit breaker** - Built-in resilience with exponential backoff and circuit breaker
- **Type-safe** - Full PHP 8.2+ type hints and readonly DTOs
- **PSR-compliant** - Works with any PSR-compatible HTTP client and logger
- **Framework agnostic** - Use with Laravel, Symfony, or vanilla PHP
- **Webhook ingestion** - Ingest custom webhooks into jobs; verify GitHub and Stripe signatures
- **Dead Letter Queue (DLQ)** - Manage and retry failed jobs
- **Billing integration** - Stripe-powered subscription management
- **Automatic case conversion** - Between camelCase (PHP) and snake_case (API)

## Requirements

- PHP 8.2 or higher
- Composer
- `ext-json` (usually bundled)

### Optional Extensions

- `ext-pcntl` + `ext-posix` - Required by `SpooledWorker` at startup for automatic lease renewal while synchronous handlers run (Unix-like CLI environments); REST/gRPC/realtime clients do not require them
- `ext-grpc` + `ext-protobuf` - For gRPC transport support
- WebSocket library (e.g., `ratchet/pawl`) - For WebSocket realtime support

## Installation

```bash
composer require spooled-cloud/spooled
```

### With gRPC Support

```bash
# Install PHP extensions first
pecl install grpc protobuf

# Then require gRPC packages
composer require grpc/grpc google/protobuf
```

## Quick Start

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

// Create client with API key
$client = new SpooledClient(
    new ClientOptions(apiKey: 'sp_live_your_api_key')
);

// Create a job
$job = $client->jobs->create([
    'queue' => 'email-notifications',
    'payload' => [
        'to' => 'user@example.com',
        'subject' => 'Welcome!',
        'body' => 'Thanks for signing up.',
    ],
    'priority' => 5,
    'maxRetries' => 3,
]);

echo "Created job: {$job->id}\n";

// Get job status
$status = $client->jobs->get($job->id);
echo "Status: {$status->status}\n";
```

## Documentation

| Guide | Description |
|-------|-------------|
| [Getting Started](docs/GETTING_STARTED.md) | Installation, setup, and first job |
| [Configuration](docs/configuration.md) | All configuration options, retry, and circuit breaker |
| [Workers](docs/workers.md) | SpooledWorker runtime, concurrency, and graceful shutdown |
| [Workflows](docs/workflows.md) | DAG workflows with job dependencies |
| [gRPC](docs/grpc.md) | High-performance gRPC transport |
| [Resources](docs/resources.md) | Complete API reference for all resources |
| [Publishing](docs/PUBLISHING.md) | Publishing to Packagist |

## Examples

See the [`examples/`](examples/) directory for runnable code:

| Example | Description |
|---------|-------------|
| [`basic-usage.php`](examples/basic-usage.php) | Basic SDK usage |
| [`worker-example.php`](examples/worker-example.php) | Processing jobs with SpooledWorker |
| [`workflow-example.php`](examples/workflow-example.php) | Complex workflows with dependencies |
| [`scheduled-jobs.php`](examples/scheduled-jobs.php) | Cron schedules |
| [`grpc-example.php`](examples/grpc-example.php) | High-performance gRPC transport |
| [`realtime-example.php`](examples/realtime-example.php) | SSE/WebSocket event streaming |
| [`webhook-ingestion-example.php`](examples/webhook-ingestion-example.php) | Webhook validation and ingestion |
| [`error-handling.php`](examples/error-handling.php) | Error handling patterns and retry logic |

## Real-world examples (beginner friendly)

If you want 5 copy/paste “real life” setups (Stripe → jobs, GitHub Actions → jobs, cron schedules, CSV import, website signup), see:

- `https://github.com/spooled-cloud/spooled-backend/blob/main/docs/guides/real-world-examples.md`

## Core Concepts

### Jobs

Jobs are units of work with payloads, priorities, and retry policies:

```php
<?php

$job = $client->jobs->create([
    'queue' => 'my-queue',
    'payload' => ['data' => 'value'],
    'priority' => 5,                     // -100 to 100
    'maxRetries' => 3,
    'timeoutSeconds' => 300,
    'scheduledFor' => '2024-01-15T10:00:00Z',
    'idempotencyKey' => 'unique-key',
]);

// Get job status
$job = $client->jobs->get($job->id);

// List jobs (all filters optional, combined with AND)
$jobs = $client->jobs->list([
    'queue' => 'my-queue',
    'status' => ['failed', 'deadletter'],   // one status, a comma list, or a list
    'q' => 'invoice',                        // id prefix, or text in the queue name / payload.job_type
    'jobType' => 'send-email',               // exact payload.job_type
    'createdAfter' => new DateTimeImmutable('-1 day'),  // RFC 3339 string or DateTimeInterface
    'createdBefore' => '2026-10-12T00:00:00Z',
    'tag' => 'billing',                      // a single tag
]);
// A created_after/created_before that is not RFC 3339 is a ValidationError (400).

// A job's lifecycle events, oldest first ('order' => 'desc' for newest first)
$history = $client->jobs->history($job->id, ['limit' => 100]);
foreach ($history->events as $event) {
    echo "{$event->createdAt} {$event->eventType} {$event->workerId}\n";
}

// Cancel a job (DELETE /jobs/{id}): only pending or scheduled jobs can be cancelled;
// a job a worker is already processing is not interrupted
$client->jobs->cancel($job->id);

// Boost priority
$client->jobs->boostPriority($job->id, 10);

// Bulk enqueue (one queue per batch: pass it once, or name the same queue on every job)
$result = $client->jobs->bulkEnqueue([
    'queue' => 'my-queue',
    'jobs' => [
        ['payload' => ['item' => 1]],
        ['payload' => ['item' => 2]],
    ],
]);

// Paginate: hasMore comes from the API's X-Has-More header
$offset = 0;
do {
    $page = $client->jobs->list(['queue' => 'my-queue', 'limit' => 50, 'offset' => $offset]);
    $offset += count($page->jobs);
} while ($page->hasMore);
```

Workers settle jobs they claimed with `jobs->complete()`, `jobs->fail()` and
extend them with `jobs->heartbeat()`. Each call needs the claimed job's
`leaseId` (the API answers 422 without it); `SpooledWorker` does this for you:

```php
<?php

$claimed = $client->jobs->claim(['queue' => 'my-queue', 'workerId' => $workerId, 'limit' => 1]);
foreach ($claimed->jobs as $job) {
    $client->jobs->complete($job->id, [
        'workerId' => $workerId,
        'leaseId' => $job->leaseId,
        'result' => ['ok' => true],
    ]);
}
```

### Workers

Process jobs with the built-in worker runtime. `SpooledWorker` uses short-lived
`pcntl` renewal processes so blocking synchronous handlers keep their leases
without requiring an event loop; install `ext-pcntl` and `ext-posix` in the CLI
environment.

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\JobContext;

$client = new SpooledClient(
    new ClientOptions(apiKey: 'sp_live_your_api_key')
);

$worker = new SpooledWorker($client, [
    'queueName' => 'my-queue',
    // Handlers run one at a time in this process (one job claimed per poll);
    // run several worker processes for parallelism.
    // Optional, but recommended: a stable id (1-128 chars, [A-Za-z0-9._-])
    // makes registration an upsert, so restarts reuse this worker's row
    // instead of leaving the old one against the plan worker cap.
    'workerId' => 'my-queue-worker-01',
]);

$worker->process(function (JobContext $ctx): array {
    echo "Processing job {$ctx->jobId}\n";
    
    // Access payload
    $data = $ctx->get('data');
    
    // A stop was requested: the job may still finish (its result is sent),
    // but wrapping up early keeps it inside `shutdownTimeout`.
    if ($ctx->isShuttingDown()) {
        return ['partial' => true];
    }
    
    // Return result (job completed on success). An array or object is stored
    // as that JSON value. A string, number, or bool is stored as itself, not
    // wrapped in {"result": ...}. null is stored as SQL NULL.
    return ['success' => true];
});

// Graceful shutdown: stop() only requests it and returns immediately, so it is
// safe in a signal handler. The running job finishes and is acknowledged, then
// the worker deregisters and start() returns.
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn() => $worker->stop());
pcntl_signal(SIGINT, fn() => $worker->stop());

// Start processing (blocking)
$worker->start();
```

While a handler runs, the worker's renewal process also keeps the job lease and
the worker heartbeat alive, enforces the job's `timeout_seconds` (the handler is
aborted and the job failed with `Job timeout exceeded`), and, after `stop()`,
aborts a handler still running after `shutdownTimeout` (job failed with `Worker
shutdown timeout`). See [docs/workers.md](docs/workers.md).

Registered workers report what they did: `$client->workers->get($id)` and
`workers->list()` carry `jobsProcessed` (jobs completed) and `jobsFailed`
(failures reported: each retry it caused and each dead-letter).

### Workflows (DAGs)

Orchestrate multiple jobs with dependencies:

```php
<?php

$workflow = $client->workflows->create([
    'name' => 'ETL Pipeline',
    'jobs' => [
        ['key' => 'extract', 'queue' => 'etl', 'payload' => ['step' => 'extract']],
        ['key' => 'transform', 'queue' => 'etl', 'payload' => ['step' => 'transform'], 'dependsOn' => ['extract']],
        ['key' => 'load', 'queue' => 'etl', 'payload' => ['step' => 'load'], 'dependsOn' => ['transform']],
    ],
]);

// Get workflow status
$workflow = $client->workflows->get($workflow->id);
echo "Progress: {$workflow->completedJobs}/{$workflow->totalJobs}\n";

// List workflow jobs
$jobs = $client->workflows->jobs->list($workflow->id);

// Cancel workflow
$client->workflows->cancel($workflow->id);
```

### Schedules

Run jobs on a cron schedule:

```php
<?php

$schedule = $client->schedules->create([
    'name' => 'Daily Report',
    'queue' => 'reports',
    'schedule' => '0 9 * * *',           // 5-field cron
    'payload' => ['type' => 'daily'],
    'timezone' => 'America/New_York',
]);

// List schedules
$schedules = $client->schedules->list();

// Pause/resume
$client->schedules->pause($schedule->id);
$client->schedules->resume($schedule->id);

// Trigger immediately
$job = $client->schedules->trigger($schedule->id);

// Get execution history
$runs = $client->schedules->history($schedule->id);

// Delete schedule
$client->schedules->delete($schedule->id);
```

### Realtime Events

Subscribe to real-time job events via SSE or WebSocket:

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

$client = new SpooledClient(new ClientOptions(
    apiKey: 'sp_live_your_api_key',
));

// Get unified realtime client (auto-selects best transport). It has its own
// credentials: SSE sends the API key, the WebSocket (JWT only) gets a JWT that
// is exchanged lazily and refreshed before it expires. REST calls keep using
// the API key either way.
$realtime = $client->realtime();

// Check available transport
if ($realtime->isWebSocketAvailable()) {
    echo "Using WebSocket\n";
} else {
    echo "Using SSE\n";
}

// Subscribe to queue events. Event data comes as the server sends it, with
// snake_case keys (job_id, queue_name, ...).
$realtime->subscribeToQueue('my-queue', function (array $event): void {
    echo "Event: {$event['type']} - Job: {$event['data']['job_id']}\n";
});

// Or subscribe to specific job
$realtime->subscribeToJob($jobId, function (array $event): void {
    echo "Job event: {$event['type']}\n";
});

// Stop when done
$realtime->stop();
$client->close();
```

#### Direct SSE Client

```php
<?php

use Spooled\Realtime\SseClient;

$sse = new SseClient(
    baseUrl: 'https://api.spooled.cloud',
    apiKey: 'sp_live_your_api_key',
);

// Register event handlers
$sse->on('job.created', fn($e) => print("New job!\n"));
$sse->on('job.completed', fn($e) => print("Job done!\n"));

// Subscribe to all events
$sse->subscribe(function (array $event): void {
    echo "[{$event['type']}] " . json_encode($event['data']) . "\n";
});

// Start listening (blocking)
$sse->listen();
```

### Organization Management

Manage your organization and track usage:

```php
<?php

// Get current usage and limits
$usage = $client->organizations->getUsage();
echo "Plan: {$usage->plan}\n";

// Generate a unique slug for a new organization
$slug = $client->organizations->generateSlug('My Company');

// Check if a slug is available
$result = $client->organizations->checkSlug('my-company');
echo $result['available'] ? 'Available' : 'Taken';

// List organizations
$orgs = $client->organizations->list();

// Webhook token management (the token authenticates incoming custom webhooks)
$token = $client->organizations->getWebhookToken();
$client->organizations->regenerateWebhookToken();
// There is no way to clear a token: clearWebhookToken() is deprecated and throws
// UnsupportedOperationError without a network call. Rotate it instead.
```

### Webhooks

Configure outgoing webhooks for job events:

```php
<?php

use Spooled\Types\WebhookEvent;
use Spooled\Types\WebhookFormat;

// Create webhook
$webhook = $client->webhooks->create([
    'name' => 'My Webhook',
    'url' => 'https://your-app.com/webhooks/spooled',
    'events' => ['job.completed', 'job.failed', WebhookEvent::JOB_DEADLETTERED],
    'secret' => 'hmac-secret',
]);

// Post straight into a chat channel: format slack, discord or teams turns the
// event into the message that service's incoming webhook URL accepts
$alerts = $client->webhooks->create([
    'name' => 'Failures to Slack',
    'url' => 'https://hooks.slack.com/services/...',
    'events' => [WebhookEvent::JOB_DEADLETTERED, WebhookEvent::WORKFLOW_FAILED],
    'format' => WebhookFormat::SLACK,   // or 'slack'; default 'json'
]);
echo $alerts->format; // "slack"

// Test webhook
$client->webhooks->test($webhook->id);

// Get delivery history (retained for the plan's window, see below)
$deliveries = $client->webhooks->getDeliveries($webhook->id);

// Retry a failed delivery
$client->webhooks->retryDelivery($webhook->id, $deliveryId);

// Delete webhook
$client->webhooks->delete($webhook->id);
```

Events: `job.created`, `job.started`, `job.completed`, `job.failed`,
`job.cancelled`, `job.deadlettered`, `job.retried`, `queue.paused`,
`queue.resumed`, `worker.registered`, `worker.deregistered`,
`schedule.triggered`, `workflow.completed` and `workflow.failed`
(`WebhookEvent` has a case for each). `format` is `json` (the signed JSON
envelope, the default), `slack`, `discord` or `teams`. An unknown event or
format is a `ValidationError` (400). Read back, `Webhook::$events` and
`Webhook::$format` are plain strings, so values the API adds later still parse.

On update, `secret` is three-state. Omit the key to keep the current secret,
pass a string to replace it, and pass `null` to **clear** it. Clearing means
deliveries go out unsigned, with no `X-Spooled-Signature` header, and a receiver
that verifies signatures will reject every one of them. Send only the keys you
mean to change; serialising a whole webhook back with null defaults now wipes
the secret.

```php
$client->webhooks->update($webhook->id, ['secret' => null]); // stops signing
```

After 20 consecutive failed deliveries a webhook is disabled automatically:
`enabled` becomes `false` and `lastStatus` becomes `"auto_disabled"`, and it
receives no events until it is enabled again. `failureCount` counts consecutive
failed deliveries rather than individual retry attempts, and any success resets
it to 0.

```php
if ($webhook->lastStatus === 'auto_disabled') {
    // Fix the endpoint first. Re-enabling is charged against the plan webhook
    // cap, so this can throw RateLimitError with errorCode "QUOTA_EXCEEDED".
    $client->webhooks->enable($webhook->id);
}
```

Delivery history is retained, not permanent: rows are removed once past the
plan's history retention window (free 1 day, starter 7, pro 30, enterprise 90),
and only the newest 100 deliveries per webhook are readable. Copy anything you
need to keep into your own store; once a row is swept it can no longer be
retried.

### Dead Letter Queue (DLQ)

Manage jobs that have exhausted all retries:

```php
<?php

// List DLQ jobs
$dlqJobs = $client->jobs->dlq->list(['limit' => 100]);

// Retry specific jobs from DLQ
$result = $client->jobs->dlq->retry([
    'jobIds' => ['job-1', 'job-2'],
]);

// Retry jobs by queue ('queue' and 'queueName' are the same filter; without
// one, the operation applies to EVERY queue of the organization)
$result = $client->jobs->dlq->retry([
    'queue' => 'my-queue',
    'limit' => 50,
]);

// Purge DLQ (requires confirmation)
$result = $client->jobs->dlq->purge([
    'queue' => 'my-queue',
    'confirm' => true,
]);
```

### API Key Management

Manage API keys programmatically:

```php
<?php

// Create a new API key
$apiKey = $client->apiKeys->create([
    'name' => 'Production Worker',
]);
echo "Save this key: {$apiKey->key}\n"; // Only shown once!

// List all API keys
$keys = $client->apiKeys->list();

// Update key
$client->apiKeys->update($keyId, ['name' => 'Updated Name']);

// Revoke a key
$client->apiKeys->delete($keyId);
```

`$key->lastUsedAt` is coarse. The API writes it at most once per key per five
minutes, not once per request, so it can be up to five minutes behind. Use it to
answer "has this key been used lately", not "did that request just happen", and
give rotation tooling a margin wider than the write interval before treating a
key as unused.

### Billing & Subscriptions

Manage billing via Stripe integration:

```php
<?php

// Get billing status
$status = $client->billing->getStatus();
echo "Plan: {$status->planTier}\n";

// Create customer portal session
$portal = $client->billing->createPortal([
    'returnUrl' => 'https://your-app.com/settings',
]);

// Redirect user to: $portal->url
```

### Webhook Ingestion

The API ingests one kind of webhook: `POST /webhooks/{org_id}/custom`, which
creates a job and must carry the organization's webhook token. GitHub and Stripe
events are not ingested directly: verify their signature in your own endpoint
(see the helpers below) and forward the event as a custom webhook.

```php
<?php

// Ingest custom webhook (creates a job)
$result = $client->ingest->custom(
    $orgId,
    [
        'queueName' => 'webhooks',
        'eventType' => 'user.created',
        'payload' => ['userId' => '123', 'email' => 'user@example.com'],
    ],
    ['webhookToken' => $webhookToken],  // from organizations->getWebhookToken()
);

echo "Created job: {$result->jobId}\n";

// The inbound log: requests the API accepted, newest first (no payloads or
// signatures). Filters: status (created|duplicate), since, queue, limit, offset.
$log = $client->ingest->listDeliveries(['status' => 'created', 'since' => new DateTimeImmutable('-1 hour')]);
foreach ($log->deliveries as $delivery) {
    echo "{$delivery->createdAt} {$delivery->eventType} -> {$delivery->matchedQueue} job {$delivery->jobId}\n";
}
```

The inbound log is organization-wide, so it needs an API key that is not
restricted to some queues (a queue-scoped key gets an `AuthorizationError`).

`ingest->github()` and `ingest->stripe()` are deprecated: the API has no such
routes (they always 404'd), so they now throw `UnsupportedOperationError`
without a network call.

#### Signature Validation Helpers

```php
<?php

// Generate signatures (for testing)
$githubSig = $client->ingest->generateGitHubSignature($payload, $secret);
$stripeSig = $client->ingest->generateStripeSignature($payload, $secret);

// Validate signatures manually
$valid = $client->ingest->validateGitHubSignature($payload, $signature, $secret);
$valid = $client->ingest->validateStripeSignature($payload, $signature, $secret, tolerance: 300);

// Example webhook endpoint handler
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!$client->ingest->validateGitHubSignature($payload, $signature, $secret)) {
    http_response_code(401);
    exit('Invalid signature');
}

// Process webhook...
```

## Configuration

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;
use Spooled\Config\CircuitBreakerConfig;

$client = new SpooledClient(new ClientOptions(
    // Authentication (one of these)
    apiKey: 'sp_live_...',
    // accessToken: 'jwt-token',
    // adminKey: 'admin-key',
    
    // URLs
    baseUrl: 'https://api.spooled.cloud',
    // grpcAddress: 'grpc.spooled.cloud:443',
    
    // Timeouts (seconds)
    connectTimeout: 10.0,
    requestTimeout: 30.0,
    
    // Retry configuration
    retry: new RetryConfig(
        maxRetries: 3,
        baseDelay: 1.0,      // seconds
        maxDelay: 30.0,      // seconds
        factor: 2.0,
        jitter: 0.1,
    ),
    
    // Circuit breaker
    circuitBreaker: new CircuitBreakerConfig(
        enabled: true,
        failureThreshold: 5,
        successThreshold: 2,
        timeout: 30.0,       // seconds
    ),
    
    // Custom headers
    headers: [
        'X-Custom-Header' => 'value',
    ],
    
    // PSR-3 logger
    logger: $myLogger,
));
```

## Error Handling

All errors extend `SpooledError` with specific subclasses:

```php
<?php

use Spooled\Errors\SpooledError;
use Spooled\Errors\AuthenticationError;
use Spooled\Errors\NotFoundError;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\ValidationError;

try {
    $job = $client->jobs->get('non-existent-id');
} catch (NotFoundError $e) {
    echo "Job not found: {$e->getMessage()}\n";
} catch (RateLimitError $e) {
    echo "Rate limited. Retry after: {$e->getRetryAfterSeconds()} seconds\n";
} catch (ValidationError $e) {
    echo "Validation failed: {$e->getMessage()}\n";
    foreach ($e->getFieldErrors() as $field => $errors) {
        echo "  {$field}: " . implode(', ', $errors) . "\n";
    }
} catch (AuthenticationError $e) {
    echo "Invalid API key\n";
} catch (SpooledError $e) {
    echo "Error [{$e->statusCode}]: {$e->getMessage()}\n";
    echo "Request ID: {$e->requestId}\n";
}
```

## gRPC Transport (Optional)

For high-throughput workers, use the gRPC API:

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

// Requires ext-grpc and ext-protobuf
$client = new SpooledClient(new ClientOptions(
    apiKey: 'sp_live_your_api_key',
    grpcAddress: 'grpc.spooled.cloud:443',
));

// Get gRPC client (lazy-initialized)
$grpc = $client->grpc();

// Wait for connection
$grpc->waitForReady();

// Enqueue via gRPC (higher throughput than REST)
$result = $grpc->queue->enqueue([
    'queueName' => 'fast-jobs',
    'payload' => ['data' => 'value'],
    'priority' => 5,
]);

echo "Created job: {$result['jobId']}\n";

// Get queue stats
$stats = $grpc->queue->getStats(['queueName' => 'fast-jobs']);

// For manual dequeue loops, keep the returned leaseId and send it on
// complete(), fail(), and renewLease(): the server rejects a complete or fail
// without it (INVALID_ARGUMENT). renewLease uses extensionSecs.
// dequeue() takes leaseDurationSecs (5-3600; omitted or 0 = the server default, 300 s
// on Spooled Cloud). A job that can outlive its lease needs a longer lease or a
// renewLease() call before the lease expires.
$dequeued = $grpc->queue->dequeue(['queueName' => 'fast-jobs', 'workerId' => $workerId, 'batchSize' => 1]);
foreach ($dequeued['jobs'] as $job) {
    $grpc->queue->fail([
        'jobId' => $job['id'],
        'workerId' => $workerId,
        'leaseId' => $job['leaseId'],
        'error' => 'bad input',
        'retry' => false,   // dead-letter now; omit to retry per the job's max_retries
    ]);
}

// Register worker. A stable workerId (1-128 chars, [A-Za-z0-9._-]) makes a
// re-registration after a restart update the same worker instead of adding one.
$worker = $grpc->workers->register([
    'queueName' => 'fast-jobs',
    'hostname' => gethostname(),
    'concurrency' => 10,
    'workerId' => 'fast-jobs-worker-01',
]);

// Send heartbeat
$grpc->workers->heartbeat([
    'workerId' => $worker['workerId'],
    'currentJobs' => 0,
]);

// Deregister when done
$grpc->workers->deregister(['workerId' => $worker['workerId']]);

// Clean up connections
$client->close();
```

gRPC carries `payload` and `result` as `google.protobuf.Struct`. A payload must
therefore be a JSON object: a list or scalar payload does not arrive as sent
(the server gets an empty or re-keyed object), and integers beyond 2^53 lose
precision. REST (`$client->jobs->create()`) keeps such payloads exactly.

### Standalone gRPC Client

```php
<?php

use Spooled\Grpc\SpooledGrpcClient;
use Spooled\Grpc\GrpcOptions;

$grpc = new SpooledGrpcClient(new GrpcOptions(
    address: 'grpc.spooled.cloud:443',
    apiKey: 'sp_live_your_api_key',
    secure: true,
));

$grpc->waitForReady();
// ... use $grpc->queue and $grpc->workers
$grpc->close();
```

## Plan Limits

All operations automatically enforce tier-based limits:

| Tier | Active Jobs | Daily Jobs | Queues | Workers | Webhooks |
|------|-------------|------------|--------|---------|----------|
| **Free** | 10 | 1,000 | 2 | 1 | 1 |
| **Starter** | 500 | 10,000 | 10 | 5 | 5 |
| **Pro** | 5,000 | 100,000 | 50 | 25 | 20 |
| **Enterprise** | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited |

(Source: the backend's `config/plans.rs`. Workflows: Free none, Starter 5, Pro 25.)

When a plan quota or limit is exceeded, you'll receive an `HTTP 429` (a `RateLimitError`)
with `errorCode` `"QUOTA_EXCEEDED"` and details describing the `resource`, `current`, `limit`, and `plan`:

```php
<?php

use Spooled\Errors\RateLimitError;

try {
    $client->jobs->create([/* ... */]);
} catch (RateLimitError $e) {
    if ($e->errorCode === 'QUOTA_EXCEEDED') {
        echo "Plan quota exceeded: {$e->getMessage()}\n";
    } else {
        // Request-rate limiting also returns 429, with Retry-After and the
        // X-RateLimit-* headers ($e->limit, $e->remaining, $e->reset in Unix
        // seconds, $e->scope). The plan limit is per organization in requests per
        // second; an API key's own rateLimit is requests per minute.
        echo "Rate limited. Retry after: {$e->getRetryAfterSeconds()} seconds\n";
    }
}
```

The API sends `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
(Unix time in seconds) and `X-RateLimit-Scope` on its responses, not only on a
429. The client keeps the latest values:

```php
<?php

$client->jobs->list(['limit' => 1]);
$rateLimit = $client->getLastRateLimit(); // RateLimitInfo|null
if ($rateLimit !== null && $rateLimit->remaining === 0) {
    sleep($rateLimit->secondsUntilReset() ?? 1);
}
```

A quota 429 does not clear by waiting, so the client does not retry it (`$e->isQuotaExceeded()`
tells the two apart) and it never counts toward the circuit breaker; a worker's
`complete`/`fail` calls keep working while enqueues hit the cap.

## Retries

Idempotent requests (GET, PUT, DELETE) are retried on network errors, timeouts,
408, 429 (with `Retry-After`) and 5xx, up to `RetryConfig::$maxRetries`. A plain
POST is not retried, because a lost response would run it twice: that is why
`jobs->create()` is only retried when you pass an `idempotencyKey`. Worker acks
(`jobs->complete()`, `jobs->fail()`, `jobs->heartbeat()`, `workers->heartbeat()`)
are retried too: they are fenced by the lease. If the first attempt of a
`complete` actually landed, the retry is answered with `409 Job is already completed`
(a `ConflictError`).

## Version Source

Release `1.5.0` is defined once in `Spooled\Version::VERSION`. The default HTTP `User-Agent` and default `SpooledWorker` registration version derive from that constant. Application metadata may override the worker version explicitly.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run with coverage
XDEBUG_MODE=coverage composer test:coverage

# Static analysis
composer analyse

# Code formatting
composer format

# All CI checks
composer ci
```

## Testing Scripts

The SDK includes parity test scripts that match the Node.js and Python SDK test suites:

```bash
# Run local tests (requires running backend)
API_KEY=sp_test_... BASE_URL=http://localhost:8080 composer scripts:test-local

# Run production tests: use a key of a dedicated test organization
API_KEY=sp_live_... composer scripts:test-production

# Interactive verification
composer scripts:verify-production
```

Both scripts name everything they create (queues, schedules, workflows) after
the run's prefix, `test-<unix time>-<8 hex>-`. The clean-ups cancel jobs,
cancel workflows and delete schedules only when they carry such a name (for a
schedule, both its name and its queue), so the organization's other jobs,
schedules and workflows, including ones whose names merely start with `test-`,
are never touched. Tests with side effects beyond that (organization sign-up,
an email login start, a billing portal session, regenerating the inbound
webhook token) run only with `RUN_UNSAFE_TESTS=1`, which `test-production`
always turns off. Other settings: `SKIP_GRPC`, `SKIP_STRESS`, `VERBOSE`,
`METRICS_TOKEN` (for `/metrics`), `ADMIN_API_KEY` and `TEST_RESULTS_FILE`.

## License

Apache License 2.0 - see [LICENSE](LICENSE) for details.
