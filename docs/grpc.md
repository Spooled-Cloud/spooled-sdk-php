# gRPC Guide

The PHP SDK includes an optional gRPC transport for queue and worker operations. No fixed latency or throughput multiple is guaranteed; benchmark your own payloads, network path, and server deployment.

## Requirements

The Composer runtime packages are already declared by the SDK. The PHP extensions must also be installed and enabled:

```bash
pecl install grpc protobuf
```

Verify them with:

```bash
php -m | grep -E 'grpc|protobuf'
```

## Create a Client

Via `SpooledClient`:

```php
<?php

use Spooled\Config\ClientOptions;
use Spooled\SpooledClient;

$client = new SpooledClient(new ClientOptions(
    apiKey: 'sp_live_...',
    grpcAddress: 'grpc.spooled.cloud:443',
));

$grpc = $client->grpc();
$grpc->waitForReady(new DateTime('+5 seconds'));
```

Standalone:

```php
use Spooled\Grpc\GrpcOptions;
use Spooled\Grpc\SpooledGrpcClient;

$grpc = new SpooledGrpcClient(new GrpcOptions(
    address: 'grpc.spooled.cloud:443',
    apiKey: 'sp_live_...',
    secure: true,
    timeout: 30.0,
));
```

Use `secure: true` for Spooled Cloud. Local development may use `secure: false` with `localhost:50051`.

## Enqueue and Read Jobs

```php
$result = $grpc->queue->enqueue([
    'queueName' => 'emails',
    'payload' => ['to' => 'user@example.com'],
    'priority' => 5,
    'maxRetries' => 3,
    'idempotencyKey' => 'email-order-123',
]);

echo $result['jobId'];

$lookup = $grpc->queue->getJob($result['jobId']);
$stats = $grpc->queue->getStats(['queueName' => 'emails']);
```

### Payloads travel as `google.protobuf.Struct`

gRPC carries `payload` and `result` as `google.protobuf.Struct`, which is a JSON
object. So over gRPC:

- a payload or result must be a JSON object: a list or a scalar does not arrive
  as sent (the server sees an empty or re-keyed object);
- integers beyond 2^53 lose precision (Struct numbers are doubles).

REST (`$client->jobs->create()`, `jobs->complete()`) keeps any JSON value and
large integers exactly; use it for such payloads.

## Manual Worker Loop

Register a worker, dequeue jobs, and send back the lease id returned with each job:

```php
$registration = $grpc->workers->register([
    'queueName' => 'emails',
    'hostname' => gethostname() ?: 'php-worker',
    'concurrency' => 5,
    // Optional stable id (1-128 chars, [A-Za-z0-9._-]): registering again with
    // it after a restart updates this worker instead of adding one, so a
    // restart does not count twice against the plan's worker limit.
    'workerId' => 'emails-worker-01',
    'metadata' => [
        'sdkVersion' => \Spooled\Version::VERSION,
        'environment' => 'production',
    ],
]);

$workerId = $registration['workerId'];
$result = $grpc->queue->dequeue([
    'queueName' => 'emails',
    'workerId' => $workerId,
    'batchSize' => 5,
    'leaseDurationSecs' => 60,
]);

if ($result === null) {
    // No jobs are currently available.
    return;
}

foreach ($result['jobs'] as $job) {
    try {
        $output = processEmail($job['payload']);

        $grpc->queue->complete([
            'jobId' => $job['id'],
            'workerId' => $workerId,
            'leaseId' => $job['leaseId'],
            'result' => $output,
        ]);
    } catch (Throwable $e) {
        $grpc->queue->fail([
            'jobId' => $job['id'],
            'workerId' => $workerId,
            'leaseId' => $job['leaseId'],
            'error' => $e->getMessage(),
            // Omitted or true: retry according to the job's max_retries.
            // false: move the job to the dead-letter queue now.
            'retry' => !($e instanceof InvalidArgumentException),
        ]);
    }
}

$grpc->workers->deregister(['workerId' => $workerId]);
```

Send each job's `leaseId` unchanged on complete, fail, and renew. The server requires it on complete and fail: without it the call fails with `INVALID_ARGUMENT` (code 3). A stale token is rejected server-side, preventing expired work from settling or extending a replacement lease. (`dequeue()` returns `leaseId` as `null` only from servers older than lease fencing.)

`retry` on `fail()` is an `optional bool` on the wire (field 4): the SDK sends `true` unless you pass `'retry' => false`, and only `false` dead-letters the job on this failure. SDK releases before 1.4.0 serialized `retry => false` as an absent field, so on a current server such a job was retried instead of dead-lettered.

## Renew a Lease

`renewLease()` uses `extensionSecs` (not `leaseDurationSecs`):

```php
$grpc->queue->renewLease([
    'jobId' => $job['id'],
    'workerId' => $workerId,
    'leaseId' => $job['leaseId'],
    'extensionSecs' => 60,
]);
```

`dequeue()` leases each job for `leaseDurationSecs` (5-3600 s). When it is omitted or `0` the server default applies (300 s on Spooled Cloud), so pass a lease that covers the job or renew before it expires. This SDK has no `StreamJobs` wrapper; a raw `StreamJobs` stream delivers one job at a time and sends the next only after the previous one is completed or failed.

A manual loop is responsible for scheduling renewals before the lease expires and for stopping renewal before complete/fail. If you use `pcntl_fork`, create the gRPC client in the child rather than reusing a connection opened before the fork, and always terminate/reap the child before settlement.

The REST-based `SpooledWorker` already handles this lifecycle for synchronous handlers; see [workers.md](workers.md).

## Worker Heartbeats

Worker-registration heartbeats are separate from per-job lease renewal:

```php
$response = $grpc->workers->heartbeat([
    'workerId' => $workerId,
    'currentJobs' => 1,
    'status' => 'healthy',
]);

if ($response['shouldDrain']) {
    // Stop claiming new jobs and finish current work.
}
```

## Error Handling

Transport failures throw `RuntimeException` with the gRPC status details and code.

A lost or mismatched lease on `complete`, `fail`, or `renewLease` throws `Spooled\Grpc\LeaseLostException`. It extends `RuntimeException` (the type these calls always threw, with the gRPC status code 9 and the message `gRPC error: Lease expired`, or `gRPC error: lease_id does not match the job's current lease; this worker no longer holds it`), and its `errorCode` is `LEASE_EXPIRED`, the code REST uses for the same case. Do not retry settlement with the old `leaseId`.

Other `FAILED_PRECONDITION` results, including `Job is already completed`, stay a `RuntimeException` whose code is the gRPC status (9).

## Connection Management

```php
$grpc->close();
// Or close all lazy resources owned by the parent client:
$client->close();
```

## Proto Reference

The generated stubs are under `src/Grpc/Stubs/` (namespace `Spooled\V1`). The shared protocol definition is maintained in the backend repository at `proto/spooled.proto`. Regenerate with `php scripts/generate-grpc.php` (needs `protoc` and `grpc_php_plugin`), or `php scripts/generate-grpc.php --messages-only` when only messages changed and `grpc_php_plugin` is not installed; the script prints the `protoc` version and the proto SHA-256 to record in the release.