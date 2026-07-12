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

## Manual Worker Loop

Register a worker, dequeue jobs, and preserve the lease fencing token returned with each job:

```php
$registration = $grpc->workers->register([
    'queueName' => 'emails',
    'hostname' => gethostname() ?: 'php-worker',
    'concurrency' => 5,
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

        $completion = [
            'jobId' => $job['id'],
            'workerId' => $workerId,
            'result' => $output,
        ];
        if ($job['leaseId'] !== null) {
            $completion['leaseId'] = $job['leaseId'];
        }
        $grpc->queue->complete($completion);
    } catch (Throwable $e) {
        $failure = [
            'jobId' => $job['id'],
            'workerId' => $workerId,
            'error' => $e->getMessage(),
            'retry' => true,
        ];
        if ($job['leaseId'] !== null) {
            $failure['leaseId'] = $job['leaseId'];
        }
        $grpc->queue->fail($failure);
    }
}

$grpc->workers->deregister(['workerId' => $workerId]);
```

`dequeue()` returns `leaseId` as `null` when connected to a legacy server. When present, echo it unchanged on complete, fail, and renew. A stale token is rejected server-side, preventing expired work from settling or extending a replacement lease.

## Renew a Lease

`renewLease()` uses `extensionSecs` (not `leaseDurationSecs`):

```php
$renewal = [
    'jobId' => $job['id'],
    'workerId' => $workerId,
    'extensionSecs' => 60,
];
if ($job['leaseId'] !== null) {
    $renewal['leaseId'] = $job['leaseId'];
}
$grpc->queue->renewLease($renewal);
```

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

Transport failures throw `RuntimeException` with the gRPC status details and code. In particular, a stale lease is reported by the backend as a failed-precondition status. Do not retry settlement with an old `leaseId`; stop processing that execution.

## Connection Management

```php
$grpc->close();
// Or close all lazy resources owned by the parent client:
$client->close();
```

## Proto Reference

The generated stubs are under `src/Grpc/Stubs/`. The shared protocol definition is maintained in the backend repository at `proto/spooled.proto`.