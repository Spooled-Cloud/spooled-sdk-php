# gRPC Guide

Spooled provides a high-performance gRPC API for scenarios requiring maximum throughput.

## Performance

The gRPC API is significantly faster than the HTTP API:

- **HTTP API**: ~100-1400ms per request
- **gRPC API**: ~50ms per request
- **Throughput**: Suitable for 1000+ jobs/second per worker

Performance optimizations:
- ✅ **Redis API key caching** eliminates bcrypt verification on cache hits
- ✅ **Batch operations** reduce round trips
- ✅ **Connection pooling** reuses HTTP/2 streams
- ✅ **Binary serialization** with Protobuf

## When to Use gRPC

| Use Case | Recommended API |
|----------|-----------------|
| Web/mobile apps | REST |
| Dashboard/admin interfaces | REST |
| High-throughput workers | **gRPC** |
| Low-latency operations | **gRPC** |
| Batch processing | **gRPC** |

## Prerequisites

Install the gRPC PHP extension and packages:

```bash
# Install PHP extensions
pecl install grpc protobuf

# Add to php.ini
extension=grpc.so
extension=protobuf.so

# Install Composer packages (already included in SDK)
composer require grpc/grpc google/protobuf
```

## Basic Setup

```php
use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Grpc\SpooledGrpcClient;
use Spooled\Grpc\GrpcOptions;

// Option 1: Use gRPC client via SpooledClient
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_...',
        'grpcAddress' => 'grpc.spooled.cloud:443',
    ])
);

$grpc = $client->grpc();

// Option 2: Create standalone gRPC client
$grpc = new SpooledGrpcClient(
    GrpcOptions::fromArray([
        'address' => 'grpc.spooled.cloud:443',
        'apiKey' => 'sk_live_...',
        'secure' => true,  // Use TLS
        'timeout' => 30,
    ])
);

// Wait for connection to be ready
$grpc->waitForReady(new DateTime('+5 seconds'));
```

## Enqueue Jobs

```php
// Single job
$result = $grpc->queue->enqueue([
    'queueName' => 'emails',
    'payload' => ['to' => 'user@example.com', 'subject' => 'Hello'],
    'priority' => 5,
    'maxRetries' => 3,
]);

echo "Job ID: {$result['jobId']}\n";
echo "Created: " . ($result['created'] ? 'yes' : 'no') . "\n";

// With idempotency key (prevents duplicates)
$result = $grpc->queue->enqueue([
    'queueName' => 'payments',
    'payload' => ['amount' => 100, 'currency' => 'USD'],
    'idempotencyKey' => 'payment-order-123',
]);
```

## Dequeue Jobs (Batch)

```php
// Register worker first
$registration = $grpc->workers->register([
    'queueName' => 'emails',
    'hostname' => gethostname(),
    'maxConcurrency' => 10,
]);

$workerId = $registration['workerId'];

// Dequeue batch of jobs
$result = $grpc->queue->dequeue([
    'queueName' => 'emails',
    'workerId' => $workerId,
    'batchSize' => 10,
    'leaseDurationSecs' => 300,
]);

foreach ($result['jobs'] as $job) {
    echo "Processing job {$job['id']}\n";
    $payload = $job['payload'];
    
    try {
        processJob($payload);
        
        // Complete the job
        $grpc->queue->complete([
            'jobId' => $job['id'],
            'workerId' => $workerId,
            'result' => ['sent' => true],
        ]);
    } catch (Exception $e) {
        // Fail the job
        $grpc->queue->fail([
            'jobId' => $job['id'],
            'workerId' => $workerId,
            'error' => $e->getMessage(),
            'retry' => true,
        ]);
    }
}

// Deregister when done
$grpc->workers->deregister(['workerId' => $workerId]);
```

## Get Job by ID

```php
$result = $grpc->queue->getJob('job_abc123');

if ($result['job'] !== null) {
    echo "Job ID: {$result['job']['id']}\n";
    echo "Status: {$result['job']['status']}\n";
    echo "Queue: {$result['job']['queueName']}\n";
} else {
    echo "Job not found\n";
}
```

## Complete and Fail Jobs

```php
// Complete a job with result
$grpc->queue->complete([
    'jobId' => $jobId,
    'workerId' => $workerId,
    'result' => [
        'processed' => true,
        'items_count' => 42,
    ],
]);

// Fail a job with retry
$grpc->queue->fail([
    'jobId' => $jobId,
    'workerId' => $workerId,
    'error' => 'Connection timeout to external API',
    'retry' => true,  // Will be retried
]);

// Fail a job without retry (goes to DLQ)
$grpc->queue->fail([
    'jobId' => $jobId,
    'workerId' => $workerId,
    'error' => 'Invalid payload format',
    'retry' => false,  // Goes to dead-letter queue
]);
```

## Renew Lease

Keep jobs alive during long processing:

```php
// Renew lease for another 5 minutes
$grpc->queue->renewLease([
    'jobId' => $jobId,
    'workerId' => $workerId,
    'leaseDurationSecs' => 300,
]);
```

### Periodic Lease Renewal Pattern

```php
function processWithLeaseRenewal(SpooledGrpcClient $grpc, array $job, string $workerId): void
{
    $jobId = $job['id'];
    $running = true;
    
    // Start lease renewal in background
    $renewalPid = pcntl_fork();
    if ($renewalPid === 0) {
        // Child process: renew lease every 30 seconds
        while ($running) {
            sleep(30);
            try {
                $grpc->queue->renewLease([
                    'jobId' => $jobId,
                    'workerId' => $workerId,
                    'leaseDurationSecs' => 60,
                ]);
            } catch (Exception $e) {
                // Log but continue
            }
        }
        exit(0);
    }
    
    try {
        // Process the job
        longRunningProcess($job['payload']);
        
        $grpc->queue->complete([
            'jobId' => $jobId,
            'workerId' => $workerId,
        ]);
    } finally {
        $running = false;
        posix_kill($renewalPid, SIGTERM);
    }
}
```

## Queue Statistics

```php
$stats = $grpc->queue->getStats(['queueName' => 'emails']);

echo "Queue: {$stats['queueName']}\n";
echo "Pending: {$stats['pending']}\n";
echo "Processing: {$stats['processing']}\n";
echo "Completed: {$stats['completed']}\n";
echo "Failed: {$stats['failed']}\n";
echo "Dead Letter: {$stats['deadLetter']}\n";
```

## Worker Management

```php
// Register a worker
$registration = $grpc->workers->register([
    'queueName' => 'emails',
    'hostname' => gethostname(),
    'maxConcurrency' => 10,
    'metadata' => [
        'version' => '1.0.0',
        'environment' => 'production',
    ],
]);

$workerId = $registration['workerId'];
$heartbeatInterval = $registration['heartbeatIntervalSecs'];

// Send heartbeat
$grpc->workers->heartbeat([
    'workerId' => $workerId,
    'currentJobs' => 5,
    'status' => 'healthy',
]);

// Deregister worker
$grpc->workers->deregister(['workerId' => $workerId]);
```

## Error Handling

```php
use Spooled\Errors\SpooledError;

try {
    $result = $grpc->queue->enqueue([
        'queueName' => 'emails',
        'payload' => ['to' => 'user@example.com'],
    ]);
} catch (SpooledError $e) {
    echo "Error: {$e->getMessage()}\n";
    echo "Code: {$e->getCode()}\n";
    
    // Check for specific error types
    if (str_contains($e->getMessage(), 'RESOURCE_EXHAUSTED')) {
        echo "Plan limit exceeded!\n";
    } elseif (str_contains($e->getMessage(), 'UNAUTHENTICATED')) {
        echo "Invalid API key!\n";
    }
}
```

## Plan Limits

All gRPC operations automatically enforce tier-based limits:

- ✅ **Enqueue operations** check daily and active job limits
- ✅ **Worker registration** enforces worker limits
- ✅ **Batch operations** validate all jobs in the batch

```php
try {
    $grpc->queue->enqueue([...]);
} catch (SpooledError $e) {
    if (str_contains($e->getMessage(), 'limit reached')) {
        echo "Upgrade your plan for higher limits\n";
        // Example: "active jobs limit reached (10/10). Upgrade to starter for higher limits."
    }
}
```

## Connection Management

### TLS Configuration

```php
// Spooled Cloud (always use TLS)
$grpc = new SpooledGrpcClient(
    GrpcOptions::fromArray([
        'address' => 'grpc.spooled.cloud:443',
        'apiKey' => 'sk_live_...',
        'secure' => true,
    ])
);

// Local development (no TLS)
$grpc = new SpooledGrpcClient(
    GrpcOptions::fromArray([
        'address' => 'localhost:50051',
        'apiKey' => 'sk_test_...',
        'secure' => false,
    ])
);
```

### Connection Ready Check

```php
try {
    $grpc->waitForReady(new DateTime('+5 seconds'));
    echo "Connected to gRPC server\n";
} catch (Exception $e) {
    echo "Connection failed: {$e->getMessage()}\n";
}
```

### Closing Connection

```php
// Always close when done
$grpc->close();
```

## Full Worker Example

```php
<?php

require_once 'vendor/autoload.php';

use Spooled\Grpc\SpooledGrpcClient;
use Spooled\Grpc\GrpcOptions;

$grpc = new SpooledGrpcClient(
    GrpcOptions::fromArray([
        'address' => 'grpc.spooled.cloud:443',
        'apiKey' => getenv('SPOOLED_API_KEY'),
        'secure' => true,
    ])
);

$grpc->waitForReady(new DateTime('+10 seconds'));

// Register worker
$registration = $grpc->workers->register([
    'queueName' => 'emails',
    'hostname' => gethostname(),
    'maxConcurrency' => 10,
]);

$workerId = $registration['workerId'];
$running = true;

// Handle shutdown
pcntl_signal(SIGTERM, function () use (&$running) {
    echo "Shutting down...\n";
    $running = false;
});
pcntl_signal(SIGINT, function () use (&$running) {
    echo "Shutting down...\n";
    $running = false;
});

echo "Worker started: {$workerId}\n";

while ($running) {
    pcntl_signal_dispatch();
    
    // Send heartbeat
    $grpc->workers->heartbeat([
        'workerId' => $workerId,
        'currentJobs' => 0,
        'status' => 'healthy',
    ]);
    
    // Dequeue jobs
    $result = $grpc->queue->dequeue([
        'queueName' => 'emails',
        'workerId' => $workerId,
        'batchSize' => 5,
        'leaseDurationSecs' => 60,
    ]);
    
    if (empty($result['jobs'])) {
        sleep(1);  // No jobs, wait before polling again
        continue;
    }
    
    foreach ($result['jobs'] as $job) {
        if (!$running) break;
        
        echo "Processing: {$job['id']}\n";
        
        try {
            // Your processing logic here
            $payload = $job['payload'];
            processEmail($payload);
            
            $grpc->queue->complete([
                'jobId' => $job['id'],
                'workerId' => $workerId,
                'result' => ['sent' => true],
            ]);
            
            echo "Completed: {$job['id']}\n";
        } catch (Exception $e) {
            $grpc->queue->fail([
                'jobId' => $job['id'],
                'workerId' => $workerId,
                'error' => $e->getMessage(),
                'retry' => true,
            ]);
            
            echo "Failed: {$job['id']} - {$e->getMessage()}\n";
        }
    }
}

// Cleanup
$grpc->workers->deregister(['workerId' => $workerId]);
$grpc->close();

echo "Worker stopped\n";

function processEmail(array $payload): void
{
    // Simulate email sending
    usleep(100000);
}
```

## Self-Hosted gRPC

For self-hosted deployments:

```php
// With TLS
$grpc = new SpooledGrpcClient(
    GrpcOptions::fromArray([
        'address' => 'grpc.your-company.com:443',
        'apiKey' => 'sk_live_...',
        'secure' => true,
    ])
);

// Without TLS (development only)
$grpc = new SpooledGrpcClient(
    GrpcOptions::fromArray([
        'address' => 'localhost:50051',
        'apiKey' => 'sk_test_...',
        'secure' => false,
    ])
);
```

## Proto File Reference

The proto file is available at:
- SDK: `vendor/spooled-cloud/spooled/src/Grpc/proto/spooled.proto`
- GitHub: [spooled-backend/proto/spooled.proto](https://github.com/spooled-cloud/spooled-backend/blob/main/proto/spooled.proto)

Key services:
- `QueueService`: Enqueue, Dequeue, Complete, Fail, RenewLease, GetQueueStats, GetJob
- `WorkerService`: Register, Deregister, Heartbeat
