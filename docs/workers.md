# Workers Guide

This guide documents `SpooledWorker` in Spooled PHP SDK 1.6.0.

## Runtime Requirements

`SpooledWorker::start()` requires `ext-pcntl` and `ext-posix` in a Unix-like CLI environment. The worker uses a short-lived forked child process to renew each claimed job's lease, and to keep the worker heartbeat going, while the parent runs a blocking synchronous handler. REST, gRPC, realtime, and other resource clients do not require these extensions.

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

$worker->start(); // Blocking until stop() has been requested and the running job is acknowledged.
```

## Worker Configuration

```php
$config = new WorkerConfig(
    queueName: 'emails',
    concurrency: 5,           // advertised capacity only: handlers run one at a time
    pollInterval: 1000,       // milliseconds
    leaseDuration: 60,        // seconds
    heartbeatFraction: 0.5,   // renew after 50% of the lease; must be > 0 and < 1
    shutdownTimeout: 30000,   // milliseconds the running handler may take after stop()
    heartbeatInterval: 15000, // worker heartbeat, milliseconds (also sent while a handler runs)
    hostname: gethostname() ?: 'php-worker',
    workerType: 'php',
    // version defaults to Spooled\Version::VERSION (1.6.0)
    metadata: ['environment' => 'production'],
    workerId: 'emails-worker-01', // stable registration identity
);
```

`WorkerConfig::fromArray()` is also supported for dynamic configuration. `queueName`/`queue` are accepted aliases.

The current runtime executes its synchronous handler in the polling process, so jobs are handled serially: each poll claims exactly one job. (Claiming `concurrency` jobs at once only parked the extra ones on a lease that expired while earlier handlers ran.) `concurrency` is the capacity advertised at registration; it does not create parallel handler execution. Run multiple worker processes for parallelism.

## Worker Identity

`workerId` is 1-128 characters from `[A-Za-z0-9._-]`. When set, `start()` sends it and registration becomes an upsert: the process reuses the row it already owns across restarts, and re-registering an id you own is not charged against the plan worker cap.

Leave it null and the server mints a UUID per registration, as before. Every restart then leaves the previous row holding a worker-cap slot until the stale-worker reaper clears it about two minutes later, so a crash-looping worker on a tight plan can quota itself out of registering with `HTTP 429` (`RateLimitError`, `errorCode` `"QUOTA_EXCEEDED"`).

Derive the value from something that survives a restart - the hostname, the supervisor process number, the replica ordinal - never from a PID or a freshly generated UUID, which defeat the upsert. An id already owned by a different organization is rejected with `HTTP 409` (`ConflictError`).

## Lease Renewal and Fencing

For every claimed job, the worker:

1. Starts an isolated renewal child before invoking the handler. The child runs in its own process group, so a SIGTERM/SIGINT sent to the worker's whole group (Supervisor `stopasgroup`, Ctrl-C) does not stop renewal while the job drains.
2. Renews after `leaseDuration * heartbeatFraction` using a fresh post-fork HTTP transport with retries disabled and a timeout bounded by the remaining lease. If a claim ever returns more than one job, the jobs waiting behind the running one are renewed too.
3. Sends the worker heartbeat every `heartbeatInterval` from the same child. The scheduler marks a worker offline after two minutes without one and releases its jobs, so a long handler would otherwise get a healthy, busy worker declared dead. A heartbeat that cannot be sent never disturbs the job.
4. Includes the immutable `leaseId` from the claim on heartbeat, completion, and failure requests. The API requires it on all three (422 without it); call `jobs->complete()`/`fail()`/`heartbeat()` yourself only with the `leaseId` of the claimed job.
5. Stops and reaps the renewal child before settling the job, preventing renewal from racing completion/failure or touching a replacement lease.
6. Cancels the active execution and emits `error` if the lease is terminally lost.

A stale fencing token is rejected by the server (`409 LEASE_EXPIRED`), so a worker must not cache or substitute lease IDs.

## Timeouts

A claimed job carries `timeout_seconds`. When a handler runs longer, the worker aborts it, emits `job:timeout`, and fails the job with `Job timeout exceeded` (a `timeout_seconds` of 0 means no deadline). The lease is not extended forever for a hung handler: the renewal child stops renewing shortly after the deadline.

The abort is a `SIGALRM` that raises `Spooled\Worker\JobTimeoutError` inside your handler. It extends `Error`, not `Exception`, so a `catch (\Exception)` in the handler cannot swallow it; do not catch `\Throwable` without re-throwing. A handler blocked inside a single native call (`curl_exec` without `CURLOPT_TIMEOUT`, a blocking socket read) only sees the signal once that call returns, so give such calls their own timeouts. The worker reserves `SIGALRM` / `pcntl_alarm()` while a handler runs.

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

## Handler Result

The value the handler returns is stored as the job's `result`.

- An array or object is stored as that JSON value. Keys are sent as written.
- A string, integer, float, or bool is stored as that JSON value. It is not wrapped in `{"result": ...}`.
- `null` is stored as SQL NULL.
- A value `json_encode` cannot represent (`NAN`, `INF`, a resource, invalid UTF-8) fails the job. The failure text starts with `Job result cannot be JSON-encoded`.

A handler that returns `"done"` is stored as `"done"`. Earlier releases wrapped a non-array return as `{"result": "done"}`; read `result` itself, not `result.result`. Arrays and objects are unchanged.

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
| `job:timeout` | `jobId`, `queueName`, `timeoutSeconds` (followed by `job:failed` once the failure is recorded) |

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

`stop()` only records the request and returns at once, so it is safe inside a signal handler (PHP runs that handler on top of the handler that is executing, so it must not wait). Then:

1. The worker stops claiming jobs. A job claimed just before the stop still runs; any further jobs of the same claim are left to their leases.
2. The running handler finishes and its result is sent: `complete` if it returned, `fail` if it threw. A handler finishing during shutdown is never dropped.
3. If the handler is still running `shutdownTimeout` milliseconds after `stop()`, it is aborted (`Spooled\Worker\ShutdownTimeoutError`) and the job is failed with `Worker shutdown timeout`.
4. The worker deregisters last, emits `stopped`, and `start()` returns.

Handlers can check `JobContext::isCancelled()` at safe points to wrap up early. Pick the orchestrator's grace period (`stop_grace_period`, `stopwaitsecs`, `terminationGracePeriodSeconds`) longer than `shutdownTimeout` plus the time to acknowledge the job.

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

Give each replica a `WorkerConfig::workerId` that is stable across restarts, read from an environment variable your orchestrator sets per replica. Redeployed containers then reuse their existing registrations instead of accumulating rows against the plan worker cap.

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
environment=APP_ENV="production",SPOOLED_WORKER_ID="spooled-worker-%(process_num)02d"
```

Provide `SPOOLED_API_KEY` through the host environment, a secret manager, or a protected Supervisor include rather than committing it in this file. Keep `stopwaitsecs` longer than `WorkerConfig::shutdownTimeout`; after that deadline Supervisor sends `SIGKILL`, which bypasses worker deregistration and graceful cleanup.

The `SPOOLED_WORKER_ID` above gives each of the four processes one stable identity; pass it to `WorkerConfig::workerId` in `worker.php`. A `SIGKILL`ed process then upserts its existing row on restart instead of registering a fresh one and leaving the un-deregistered row occupying a plan worker-cap slot until the stale-worker reaper clears it.