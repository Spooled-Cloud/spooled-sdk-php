# Spooled PHP SDK

Official PHP SDK for [Spooled Cloud](https://spooled.cloud) - a modern job queue service for distributed applications.

[**Live Demo (SpriteForge)**](https://example.spooled.cloud) • [Documentation](https://spooled.cloud/docs)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spooled-cloud/spooled.svg)](https://packagist.org/packages/spooled-cloud/spooled)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)
[![CI](https://github.com/spooled-cloud/spooled-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/spooled-cloud/spooled-sdk-php/actions/workflows/ci.yml)

## Features

- **Full REST API support** - Jobs, queues, workers, schedules, workflows, webhooks, and more
- **Worker runtime** - Process jobs with concurrency control, heartbeats, and graceful shutdown
- **Realtime events** - SSE and WebSocket clients for live job/queue events
- **Optional gRPC transport** - High-performance binary protocol when extensions are available
- **Retry & circuit breaker** - Built-in resilience with exponential backoff and circuit breaker
- **Type-safe** - Full PHP 8.2+ type hints and readonly DTOs
- **PSR-compliant** - Works with any PSR-compatible HTTP client and logger
- **Framework agnostic** - Use with Laravel, Symfony, or vanilla PHP
- **Webhook ingestion** - Validate and process GitHub, Stripe, and custom webhooks
- **Dead Letter Queue (DLQ)** - Manage and retry failed jobs
- **Billing integration** - Stripe-powered subscription management
- **Automatic case conversion** - Between camelCase (PHP) and snake_case (API)

## Requirements

- PHP 8.2 or higher
- Composer
- `ext-json` (usually bundled)

### Optional Extensions

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
    new ClientOptions(apiKey: 'sk_live_your_api_key')
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

// List jobs
$jobs = $client->jobs->list([
    'queue' => 'my-queue',
    'status' => 'pending',
    'tag' => 'billing',  // Optional: filter by a single tag
]);

// Cancel a job
$client->jobs->cancel($job->id);

// Boost priority
$client->jobs->boostPriority($job->id, 10);

// Bulk enqueue
$result = $client->jobs->bulkEnqueue([
    ['queue' => 'my-queue', 'payload' => ['item' => 1]],
    ['queue' => 'my-queue', 'payload' => ['item' => 2]],
]);
```

### Workers

Process jobs with the built-in worker runtime:

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\JobContext;

$client = new SpooledClient(
    new ClientOptions(apiKey: 'sk_live_your_api_key')
);

$worker = new SpooledWorker($client, [
    'queueName' => 'my-queue',
    'concurrency' => 10,
]);

$worker->process(function (JobContext $ctx): array {
    echo "Processing job {$ctx->jobId}\n";
    
    // Access payload
    $data = $ctx->get('data');
    
    // Check if shutting down
    if ($ctx->isShuttingDown()) {
        throw new \RuntimeException('Worker shutting down');
    }
    
    // Return result (job completed on success)
    return ['success' => true];
});

// Handle shutdown gracefully
pcntl_signal(SIGTERM, fn() => $worker->stop());
pcntl_signal(SIGINT, fn() => $worker->stop());

// Start processing (blocking)
$worker->start();
```

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
echo "Progress: {$workflow->progressPercent}%\n";

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
    apiKey: 'sk_live_your_api_key',
));

// Get unified realtime client (auto-selects best transport)
$realtime = $client->realtime();

// Check available transport
if ($realtime->isWebSocketAvailable()) {
    echo "Using WebSocket\n";
} else {
    echo "Using SSE\n";
}

// Subscribe to queue events
$realtime->subscribeToQueue('my-queue', function (array $event): void {
    echo "Event: {$event['type']} - Job: {$event['data']['jobId']}\n";
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
    apiKey: 'sk_live_your_api_key',
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
echo $result->available ? 'Available' : 'Taken';

// List organizations
$orgs = $client->organizations->list();

// Webhook token management
$token = $client->organizations->getWebhookToken();
$client->organizations->regenerateWebhookToken();
$client->organizations->clearWebhookToken();
```

### Webhooks

Configure outgoing webhooks for job events:

```php
<?php

// Create webhook
$webhook = $client->webhooks->create([
    'name' => 'My Webhook',
    'url' => 'https://your-app.com/webhooks/spooled',
    'events' => ['job.completed', 'job.failed'],
]);

// Test webhook
$client->webhooks->test($webhook->id);

// Get delivery history
$deliveries = $client->webhooks->getDeliveries($webhook->id);

// Retry a failed delivery
$client->webhooks->retryDelivery($webhook->id, $deliveryId);

// Delete webhook
$client->webhooks->delete($webhook->id);
```

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

// Retry jobs by queue
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

Validate and process incoming webhooks from GitHub, Stripe, or custom sources:

```php
<?php

// Ingest custom webhook (creates a job)
$result = $client->ingest->custom($orgId, [
    'queueName' => 'webhooks',
    'eventType' => 'user.created',
    'payload' => ['userId' => '123', 'email' => 'user@example.com'],
]);

echo "Created job: {$result['jobId']}\n";

// Ingest GitHub webhook (with raw body for signature)
$result = $client->ingest->github(
    orgId: $orgId,
    body: file_get_contents('php://input'),
    githubEvent: $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'push',
    signature: $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null,
    secret: 'your-github-secret',  // SDK computes signature if not provided
);

// Ingest Stripe webhook
$result = $client->ingest->stripe(
    orgId: $orgId,
    body: file_get_contents('php://input'),
    signature: $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null,
    secret: 'whsec_...',
);
```

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
    apiKey: 'sk_live_...',
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
    apiKey: 'sk_live_your_api_key',
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

// Register worker
$worker = $grpc->workers->register([
    'queueName' => 'fast-jobs',
    'hostname' => gethostname(),
    'concurrency' => 10,
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

### Standalone gRPC Client

```php
<?php

use Spooled\Grpc\SpooledGrpcClient;
use Spooled\Grpc\GrpcOptions;

$grpc = new SpooledGrpcClient(new GrpcOptions(
    address: 'grpc.spooled.cloud:443',
    apiKey: 'sk_live_your_api_key',
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
| **Free** | 10 | 1,000 | 5 | 3 | 2 |
| **Starter** | 100 | 100,000 | 25 | 25 | 10 |
| **Enterprise** | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited |

When limits are exceeded, you'll receive a `SpooledError` with status code 403:

```php
<?php

try {
    $client->jobs->create([/* ... */]);
} catch (SpooledError $e) {
    if ($e->statusCode === 403) {
        echo "Limit exceeded: {$e->getMessage()}\n";
    }
}
```

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
API_KEY=your-key BASE_URL=http://localhost:8080 composer scripts:test-local

# Run production tests (safe subset)
API_KEY=your-key composer scripts:test-production

# Interactive verification
composer scripts:verify-production
```

## License

Apache License 2.0 - see [LICENSE](LICENSE) for details.
