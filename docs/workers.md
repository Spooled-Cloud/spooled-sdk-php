# Workers Guide

This guide documents `SpooledWorker` in Spooled PHP SDK 1.0.21.

## Runtime Requirements

`SpooledWorker::start()` requires `ext-pcntl` and `ext-posix` in a Unix-like CLI environment. The worker uses a short-lived forked child process to renew each claimed job's lease while the parent runs a blocking synchronous handler. REST, gRPC, realtime, and other resource clients do not require these extensions.

No event loop is required. If `pcntl_fork`, signal-mask functions, or `posix_kill` are unavailable, `start()` throws before registering the worker.

## Basic Worker

```php
<?php

use Spooled\Config\ClientOptions;
use Spooled\SpooledClient;
use Spooled\Worker\JobContext;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerConfig;

$client = new SpooledClient(new ClientOptions(apiKey: 'sp_live_...'));
$worker = new SpooledWorker($client, new WorkerConfig(
    queueName: 'emails',
    leaseDuration: 60,
    heartbeatFraction: 0.5,
));

$worker->process(function (JobContext $ctx): array {
    sendEmail($ctx->payload);

    return ['sent' => true];
});

pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $worker->stop());
pcntl_signal(SIGINT, fn () => $worker->stop());

$worker->start(); // Blocking until stop() or an unrecoverable error.
```

## Worker Configuration

```php
$config = new WorkerConfig(
    queueName: 'emails',
    concurrency: 5,
    pollInterval: 1000,       // milliseconds
    leaseDuration: 60,        // seconds
    heartbeatFraction: 0.5,   // renew after 50% of the lease; must be > 0 and < 1
    shutdownTimeout: 30000,   // milliseconds
    heartbeatInterval: 15000, // worker-registration heartbeat, milliseconds
    hostname: gethostname() ?: 'php-worker',
    workerType: 'php',
    // version defaults to Spooled\Version::VERSION (1.0.21)
    metadata: ['environment' => 'production'],
);
```

`WorkerConfig::fromArray()` is also supported for dynamic configuration. `queueName`/`queue` are accepted aliases.

The current runtime executes its synchronous handler in the polling process, so claimed jobs are handled serially. `concurrency` controls advertised/claim capacity but does not create parallel handler execution. Run multiple worker processes for parallelism.

## Lease Renewal and Fencing

For every claimed job, the worker:

1. Starts an isolated renewal child before invoking the handler.
2. Renews after `leaseDuration * heartbeatFraction` using a fresh post-fork HTTP transport with retries disabled and a timeout bounded by the remaining lease.
3. Includes the immutable `leaseId` from the claim on heartbeat, completion, and failure requests when the server supplied one.
4. Stops and reaps the renewal child before settling the job, preventing renewal from racing completion/failure or touching a replacement lease.
5. Cancels the active execution and emits `error` if the lease is terminally lost.

A stale fencing token is rejected by the server (`409 LEASE_EXPIRED`), so a worker must not cache or substitute lease IDs.

## Job Context

Handlers receive `JobContext`:

```php
$worker->process(function (JobContext $ctx): array {
    $ctx->jobId;
    $ctx->queueName;
    $ctx->payload;
    $ctx->retryCount;
    $ctx->maxRetries;
    $ctx->metadata;

    $value = $ctx->get('key', 'default');
    $isRetry = $ctx->isRetry();
    $remaining = $ctx->getRemainingRetries();
    $cancelled = $ctx->isCancelled();

    return ['value' => $value];
});
```

`progress()` and `log()` are currently placeholders; they do not send progress or logs to the API.

## Events

`on()` returns an unsubscribe callable. Event handlers receive arrays with these fields:

| Event | Payload |
|---|---|
| `started` | `workerId`, `queueName` |
| `stopped` | `workerId`, `reason`, `completedJobs`, `failedJobs` |
| `error` | `error`; job settlement/renewal errors also include `jobId`, `queueName`, and `operation` |
| `job:claimed` | `jobId`, `queueName` |
| `job:started` | `jobId`, `queueName` |
| `job:completed` | `jobId`, `queueName`, `result` |
| `job:failed` | `jobId`, `queueName`, `error`, `willRetry` |

Success counters and `job:completed`/`job:failed` events advance only after the API confirms settlement. A rejected complete/fail request emits `error` instead of a false success event.

```php
$unsubscribe = $worker->on('job:completed', function (array $event): void {
    echo "Completed {$event['jobId']}\n";
});

$worker->on('error', function (array $event): void {
    error_log($event['error']->getMessage());
});

// Later, if needed:
$unsubscribe();
```

## Graceful Shutdown

`start()` owns the polling loop and blocks. Signal handlers should call `stop()` directly; do not wrap `start()` in a second `isRunning()` loop (there is no `isRunning()` method).

On stop, the worker stops polling, marks active work cancelled, waits up to `shutdownTimeout`, deregisters, and emits `stopped`. Handlers should check `JobContext::isCancelled()` at safe interruption points.

## Parallelism and Deployment

For parallel synchronous processing, supervise multiple PHP CLI worker processes (Supervisor, systemd, Kubernetes replicas, or equivalent). Each process has its own worker registration and lease-renewal children. Set `WorkerConfig::concurrency` to the capacity advertised and claimed by each process; the current PHP runtime still executes handlers serially, so process count is what provides actual parallelism.

### Docker

The official PHP CLI images can build both required worker extensions from bundled sources. gRPC/protobuf extensions are not needed unless the same application also uses the optional gRPC transport.

```dockerfile
FROM php:8.2-cli

RUN docker-php-ext-install pcntl posix

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .

STOPSIGNAL SIGTERM
CMD ["php", "worker.php"]
```

Keep the API key outside the image and give the worker enough time to honor its configured shutdown window:

```yaml
# compose.yaml
services:
  spooled-worker:
    build: .
    restart: unless-stopped
    environment:
      SPOOLED_API_KEY: ${SPOOLED_API_KEY}
      APP_ENV: production
    stop_grace_period: 45s
```

Scale with `docker compose up --scale spooled-worker=4 -d`. The PHP file should construct `WorkerConfig` (not the removed `WorkerOptions` API), enable `pcntl_async_signals(true)`, register `SIGTERM`/`SIGINT` handlers that call `$worker->stop()`, and then call the blocking `$worker->start()` as shown above. Choose `stop_grace_period` longer than `shutdownTimeout` (milliseconds) plus application cleanup time.

### Supervisor

Install/enable `pcntl` and `posix` in the CLI PHP used by Supervisor, then run multiple independent processes:

```ini
; /etc/supervisor/conf.d/spooled-worker.conf
[program:spooled-worker]
command=/usr/bin/php /var/www/app/worker.php
directory=/var/www/app
user=www-data
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=unexpected
startsecs=5
stopsignal=TERM
stopasgroup=true
killasgroup=true
stopwaitsecs=45
redirect_stderr=true
stdout_logfile=/var/log/supervisor/spooled-worker.log
environment=APP_ENV="production"
```

Provide `SPOOLED_API_KEY` through the host environment, a secret manager, or a protected Supervisor include rather than committing it in this file. Keep `stopwaitsecs` longer than `WorkerConfig::shutdownTimeout`; after that deadline Supervisor sends `SIGKILL`, which bypasses worker deregistration and graceful cleanup.