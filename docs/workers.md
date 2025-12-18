# Workers Guide

This guide covers the `SpooledWorker` runtime for processing jobs from queues.

## Basic Worker

```php
use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerOptions;

$client = new SpooledClient(
    ClientOptions::fromArray(['apiKey' => 'sk_live_...'])
);

$worker = new SpooledWorker(
    $client,
    WorkerOptions::fromArray([
        'queueName' => 'emails',
        'concurrency' => 10,
    ])
);

$worker->process(function (array $ctx) {
    echo "Processing job {$ctx['jobId']}\n";
    sendEmail($ctx['payload']);
    return ['sent' => true];
});

$worker->start();
```

## Configuration Options

```php
$worker = new SpooledWorker($client, WorkerOptions::fromArray([
    // Required
    'queueName' => 'my-queue',

    // Concurrency
    'concurrency' => 10,           // Max parallel jobs (default: 5)

    // Polling
    'pollInterval' => 1000,        // Poll every N ms (default: 1000)

    // Lease Management
    'leaseDuration' => 30,         // Lease duration in seconds (default: 30)
    'heartbeatInterval' => 10,     // Heartbeat every N seconds (default: 10)

    // Lifecycle
    'shutdownTimeout' => 30000,    // Max wait for graceful shutdown (default: 30000)

    // Identification
    'hostname' => gethostname(),   // Worker hostname
    'workerType' => 'php',         // Worker type identifier
    'version' => '1.0.0',          // Application version
    'metadata' => [                // Custom metadata
        'env' => 'production',
        'region' => 'us-east-1',
    ],
]));
```

## Job Context

The `process` handler receives a context array:

```php
$worker->process(function (array $ctx) {
    // Available context keys
    $ctx['jobId'];       // Unique job ID (string)
    $ctx['queueName'];   // Queue name (string)
    $ctx['payload'];     // Job payload (array - your data)
    $ctx['retryCount'];  // Current retry attempt (int, 0-indexed)
    $ctx['maxRetries'];  // Max retries configured (int)
    $ctx['workerId'];    // Worker ID (string)
    
    // Functions
    $ctx['progress'](50, 'Halfway done');  // Report progress
    
    return ['result' => 'data'];
});
```

### Example Handler

```php
$worker->process(function (array $ctx) {
    echo "Starting job {$ctx['jobId']}\n";

    // Check retry count
    if ($ctx['retryCount'] > 0) {
        echo "Retry attempt {$ctx['retryCount']}/{$ctx['maxRetries']}\n";
    }

    // Process with progress reporting
    $items = $ctx['payload']['items'] ?? [];
    $total = count($items);
    
    foreach ($items as $i => $item) {
        processItem($item);
        $ctx['progress'](($i + 1) / $total * 100, "Processed item " . ($i + 1));
    }

    return ['processedItems' => $total];
});
```

## Event Handlers

Workers emit events throughout their lifecycle:

```php
// Worker lifecycle
$worker->on('started', function (array $event) {
    echo "Worker {$event['workerId']} started on {$event['queueName']}\n";
});

$worker->on('stopped', function (array $event) {
    echo "Worker stopped: {$event['reason']}\n";
});

$worker->on('error', function (array $event) {
    echo "Worker error: {$event['error']->getMessage()}\n";
});

// Job lifecycle
$worker->on('job:claimed', function (array $event) {
    echo "Claimed job {$event['jobId']}\n";
});

$worker->on('job:started', function (array $event) {
    echo "Started processing {$event['jobId']}\n";
});

$worker->on('job:completed', function (array $event) {
    echo "Completed {$event['jobId']}: " . json_encode($event['result']) . "\n";
});

$worker->on('job:failed', function (array $event) {
    echo "Failed {$event['jobId']}: {$event['error']}\n";
    if ($event['willRetry']) {
        echo "Will retry\n";
    }
});
```

## Graceful Shutdown

Proper shutdown ensures in-progress jobs complete:

```php
$worker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'emails',
    'shutdownTimeout' => 30000, // Wait up to 30 seconds
]));

// Handle shutdown signals
pcntl_signal(SIGTERM, function () use ($worker) {
    echo "Received SIGTERM, shutting down...\n";
    $worker->stop();
});

pcntl_signal(SIGINT, function () use ($worker) {
    echo "Received SIGINT, shutting down...\n";
    $worker->stop();
});

// Start worker with signal handling
$worker->process(function (array $ctx) {
    // Your job processing logic
    return ['done' => true];
});

// This will block until stopped
while ($worker->isRunning()) {
    pcntl_signal_dispatch();
    usleep(100000); // 100ms
}

echo "Worker stopped gracefully\n";
```

### Shutdown Behavior

1. Worker stops polling for new jobs
2. Worker heartbeat stops
3. In-progress jobs continue to completion
4. Wait for jobs to complete (up to `shutdownTimeout`)
5. Force-fail any remaining jobs after timeout
6. Deregister worker from API

## Error Handling

### Throwing Errors

Throwing an error marks the job as failed:

```php
$worker->process(function (array $ctx) {
    $user = findUser($ctx['payload']['userId']);

    if (!$user) {
        throw new Exception("User not found: {$ctx['payload']['userId']}");
    }

    sendEmail($user['email'], $ctx['payload']['template']);
    return ['sent' => true];
});
```

### Retry vs No-Retry Errors

By default, failed jobs are retried up to `maxRetries`. To prevent retries, throw a `NonRetryableError`:

```php
use Spooled\Errors\NonRetryableError;

$worker->process(function (array $ctx) {
    if (!isValidPayload($ctx['payload'])) {
        // This job won't be retried - goes straight to DLQ
        throw new NonRetryableError('Invalid payload format');
    }
    
    // This might be retried on failure
    return processJob($ctx['payload']);
});
```

## Worker State

Check worker status programmatically:

```php
echo "State: " . $worker->getState() . "\n";
// 'idle' | 'starting' | 'running' | 'stopping' | 'stopped' | 'error'

echo "Worker ID: " . ($worker->getWorkerId() ?? 'not started') . "\n";

echo "Active jobs: " . $worker->getActiveJobCount() . "\n";

echo "Is running: " . ($worker->isRunning() ? 'yes' : 'no') . "\n";
```

## Multiple Workers

Run multiple workers for different queues:

```php
$emailWorker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'emails',
    'concurrency' => 10,
]));

$reportWorker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'reports',
    'concurrency' => 2, // Reports are CPU-intensive
]));

$emailWorker->process(function ($ctx) {
    return sendEmail($ctx['payload']);
});

$reportWorker->process(function ($ctx) {
    return generateReport($ctx['payload']);
});

// Start both workers
$emailWorker->start();
$reportWorker->start();

// Handle shutdown for all
$shutdown = function () use ($emailWorker, $reportWorker) {
    $emailWorker->stop();
    $reportWorker->stop();
};

pcntl_signal(SIGTERM, $shutdown);
pcntl_signal(SIGINT, $shutdown);

// Main loop
while ($emailWorker->isRunning() || $reportWorker->isRunning()) {
    pcntl_signal_dispatch();
    usleep(100000);
}
```

## Concurrency Patterns

### CPU-Bound Work

For CPU-intensive tasks, limit concurrency:

```php
$worker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'image-processing',
    'concurrency' => 2, // Match CPU cores - 2
]));
```

### I/O-Bound Work

For I/O-heavy tasks (HTTP, database), increase concurrency:

```php
$worker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'api-calls',
    'concurrency' => 50, // Many parallel I/O operations
]));
```

## Long-Running Jobs

For jobs that take longer than the lease duration:

```php
$worker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'video-processing',
    'leaseDuration' => 300,       // 5 minute lease
    'heartbeatInterval' => 60,    // Heartbeat every minute
]));

$worker->process(function (array $ctx) {
    // Long-running process
    // Heartbeats are automatically sent to extend the lease
    processVideo($ctx['payload']['videoUrl']);
    return ['processed' => true];
});
```

## Full Production Example

```php
<?php

require_once 'vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerOptions;
use Spooled\Errors\NonRetryableError;

// Initialize client
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => getenv('SPOOLED_API_KEY'),
    ])
);

// Create worker
$worker = new SpooledWorker($client, WorkerOptions::fromArray([
    'queueName' => 'emails',
    'concurrency' => 10,
    'leaseDuration' => 60,
    'heartbeatInterval' => 20,
    'shutdownTimeout' => 30000,
    'hostname' => gethostname(),
    'metadata' => [
        'version' => '1.0.0',
        'environment' => getenv('APP_ENV') ?: 'production',
    ],
]));

// Setup event handlers
$worker->on('started', function ($event) {
    echo "[" . date('Y-m-d H:i:s') . "] Worker started: {$event['workerId']}\n";
});

$worker->on('stopped', function ($event) {
    echo "[" . date('Y-m-d H:i:s') . "] Worker stopped: {$event['reason']}\n";
});

$worker->on('job:completed', function ($event) {
    echo "[" . date('Y-m-d H:i:s') . "] ✓ Completed: {$event['jobId']}\n";
});

$worker->on('job:failed', function ($event) {
    echo "[" . date('Y-m-d H:i:s') . "] ✗ Failed: {$event['jobId']} - {$event['error']}\n";
});

// Define job handler
$worker->process(function (array $ctx) {
    $payload = $ctx['payload'];
    
    // Validate payload
    if (empty($payload['to']) || empty($payload['template'])) {
        throw new NonRetryableError('Missing required fields: to, template');
    }
    
    // Send email
    $result = sendEmail(
        to: $payload['to'],
        template: $payload['template'],
        data: $payload['data'] ?? []
    );
    
    return [
        'sent' => true,
        'messageId' => $result['messageId'],
    ];
});

// Setup signal handlers
$running = true;

pcntl_signal(SIGTERM, function () use (&$running, $worker) {
    echo "\nReceived SIGTERM, initiating graceful shutdown...\n";
    $running = false;
    $worker->stop();
});

pcntl_signal(SIGINT, function () use (&$running, $worker) {
    echo "\nReceived SIGINT, initiating graceful shutdown...\n";
    $running = false;
    $worker->stop();
});

// Start worker
echo "Starting email worker...\n";
$worker->start();

// Main loop
while ($running && $worker->isRunning()) {
    pcntl_signal_dispatch();
    usleep(100000); // 100ms
}

// Cleanup
$client->close();
echo "Worker terminated.\n";

// Helper function
function sendEmail(string $to, string $template, array $data): array
{
    // Your email sending implementation
    return ['messageId' => uniqid('msg_')];
}
```

## Docker Deployment

```dockerfile
FROM php:8.2-cli

# Install extensions
RUN apt-get update && apt-get install -y \
    libgrpc-dev \
    && pecl install grpc protobuf \
    && docker-php-ext-enable grpc protobuf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

# Run worker
CMD ["php", "worker.php"]
```

```yaml
# docker-compose.yml
services:
  email-worker:
    build: .
    environment:
      - SPOOLED_API_KEY=sk_live_...
      - APP_ENV=production
    deploy:
      replicas: 3
      restart_policy:
        condition: on-failure
    stop_grace_period: 30s
```

## Supervisor Configuration

```ini
; /etc/supervisor/conf.d/spooled-worker.conf
[program:spooled-email-worker]
command=/usr/bin/php /var/www/app/worker.php
directory=/var/www/app
user=www-data
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=10
stopwaitsecs=30
stdout_logfile=/var/log/supervisor/spooled-worker.log
stderr_logfile=/var/log/supervisor/spooled-worker-error.log
environment=SPOOLED_API_KEY="sk_live_...",APP_ENV="production"
```

