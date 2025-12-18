# Getting Started with Spooled PHP SDK

This guide will help you get started with the Spooled PHP SDK.

## Installation

Install via Composer:

```bash
composer require spooled-cloud/spooled
```

## Requirements

- PHP 8.2 or higher
- Composer
- ext-json (usually bundled)

### Optional Extensions

- `ext-grpc` + `ext-protobuf` - For gRPC transport support
- WebSocket library (e.g., `ratchet/pawl`) - For WebSocket realtime support

## Quick Start

### 1. Create a Client

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

$client = new SpooledClient(new ClientOptions(
    apiKey: 'sk_live_your_api_key',
));
```

### 2. Create a Job

```php
<?php

$result = $client->jobs->create([
    'queue' => 'my-queue',
    'payload' => [
        'task' => 'process-order',
        'orderId' => 123,
    ],
    'priority' => 5,
    'maxRetries' => 3,
]);

echo "Created job: {$result->id}\n";

// Get the full job details
$job = $client->jobs->get($result->id);
echo "Status: {$job->status}\n";
```

### 3. Process Jobs with a Worker

```php
<?php

use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerConfig;
use Spooled\Worker\JobContext;

$worker = new SpooledWorker($client, new WorkerConfig(
    queueName: 'my-queue',
    concurrency: 5,
));

// Define job handler
$worker->process(function (JobContext $ctx): array {
    echo "Processing job {$ctx->jobId}\n";
    
    // Access payload
    $orderId = $ctx->get('orderId');
    
    // Do work...
    
    // Return result
    return ['processed' => true, 'orderId' => $orderId];
});

// Handle graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, fn() => $worker->stop());
    pcntl_signal(SIGINT, fn() => $worker->stop());
}

// Start worker (blocking)
$worker->start();
```

## Configuration Options

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;
use Spooled\Config\CircuitBreakerConfig;

$client = new SpooledClient(new ClientOptions(
    // Authentication (one of these)
    apiKey: 'sk_live_your_api_key',
    // accessToken: 'jwt-token',
    
    // URLs (optional)
    baseUrl: 'https://api.spooled.cloud',
    
    // Timeouts
    connectTimeout: 10.0,  // seconds
    requestTimeout: 30.0,  // seconds
    
    // Retry configuration
    retry: new RetryConfig(
        maxRetries: 3,
        baseDelay: 1.0,
        maxDelay: 30.0,
        factor: 2.0,
        jitter: 0.1,
    ),
    
    // Circuit breaker
    circuitBreaker: new CircuitBreakerConfig(
        enabled: true,
        failureThreshold: 5,
        successThreshold: 2,
        timeout: 30.0,
    ),
    
    // Custom headers
    headers: [
        'X-Custom-Header' => 'value',
    ],
));
```

## Error Handling

```php
<?php

use Spooled\Errors\SpooledError;
use Spooled\Errors\AuthenticationError;
use Spooled\Errors\NotFoundError;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\ValidationError;

try {
    $job = $client->jobs->get('invalid-id');
} catch (NotFoundError $e) {
    echo "Job not found: {$e->getMessage()}\n";
} catch (RateLimitError $e) {
    echo "Rate limited. Retry after: {$e->getRetryAfterSeconds()}s\n";
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

## Creating Jobs

### Basic Job

```php
$result = $client->jobs->create([
    'queue' => 'my-queue',
    'payload' => ['data' => 'value'],
]);
```

### Job with Options

```php
$result = $client->jobs->create([
    'queue' => 'my-queue',
    'payload' => ['data' => 'value'],
    'priority' => 10,                    // -100 to 100
    'maxRetries' => 5,
    'timeoutSeconds' => 300,
    'scheduledFor' => '2024-01-15T10:00:00Z',
    'idempotencyKey' => 'unique-key',
    'tags' => ['important', 'order'],
    'metadata' => ['source' => 'api'],
]);
```

### Bulk Enqueue

```php
$result = $client->jobs->bulkEnqueue([
    'jobs' => [
        ['queue' => 'my-queue', 'payload' => ['item' => 1]],
        ['queue' => 'my-queue', 'payload' => ['item' => 2]],
        ['queue' => 'my-queue', 'payload' => ['item' => 3]],
    ],
]);

echo "Created {$result->succeeded} jobs\n";
```

## Schedules (Cron Jobs)

```php
// Create a schedule
$schedule = $client->schedules->create([
    'name' => 'daily-report',
    'queue' => 'reports',
    'schedule' => '0 9 * * *',  // 5-field cron
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
```

## Workflows

Orchestrate multiple jobs with dependencies:

```php
$workflow = $client->workflows->create([
    'name' => 'order-processing',
    'jobs' => [
        ['key' => 'validate', 'queue' => 'orders', 'payload' => ['step' => 'validate']],
        ['key' => 'charge', 'queue' => 'payments', 'payload' => ['step' => 'charge'], 'dependsOn' => ['validate']],
        ['key' => 'fulfill', 'queue' => 'shipping', 'payload' => ['step' => 'fulfill'], 'dependsOn' => ['charge']],
    ],
]);

// Get workflow status
$status = $client->workflows->get($workflow->id);
echo "Progress: {$status->progressPercent}%\n";

// List workflow jobs
$jobs = $client->workflows->jobs->list($workflow->id);
```

## Next Steps

- See the [examples](../examples/) directory for more usage examples
- Check the [README](../README.md) for complete API reference
- Learn about [webhooks](https://docs.spooled.cloud/webhooks) and [realtime events](https://docs.spooled.cloud/realtime)
