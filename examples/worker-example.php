<?php

declare(strict_types=1);

/**
 * Worker example for the Spooled PHP SDK.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerConfig;
use Spooled\Worker\JobContext;

// Create client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
));

// Create worker
$worker = new SpooledWorker($client, new WorkerConfig(
    queueName: 'example-queue',
    concurrency: 3,
    pollInterval: 1000,  // 1 second (ms)
    heartbeatInterval: 30000,  // 30 seconds (ms)
    // Stable id: restarts upsert this worker's row instead of leaving the old
    // one against the plan worker cap until the stale-worker reaper clears it.
    workerId: getenv('SPOOLED_WORKER_ID') ?: 'example-worker-01',
));

// Register event handlers
$worker->on('started', function (array $data): void {
    echo "Worker started: {$data['workerId']}\n";
});

$worker->on('job:claimed', function (array $data): void {
    echo "Job claimed: {$data['jobId']}\n";
});

$worker->on('job:completed', function (array $data): void {
    echo "Job completed: {$data['jobId']}\n";
});

$worker->on('job:failed', function (array $data): void {
    echo "Job failed: {$data['jobId']} - {$data['error']}\n";
});

$worker->on('stopped', function (array $data): void {
    echo "Worker stopped. Completed: {$data['completedJobs']}, Failed: {$data['failedJobs']}\n";
});

// Handle shutdown signals. stop() only requests the stop and returns, so it is
// safe here: the running job finishes and is acknowledged, then start() returns.
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn () => $worker->stop());
    pcntl_signal(SIGINT, fn () => $worker->stop());
}

echo "Starting worker...\n";

// Define job handler
$worker->process(function (JobContext $ctx): array {
    echo "Processing job {$ctx->jobId} from queue {$ctx->queueName}\n";
    echo "Payload: " . json_encode($ctx->payload) . "\n";

    // Simulate work
    sleep(1);

    // A stop was requested: the job may still finish and its result is sent,
    // but wrapping up early keeps it inside `shutdownTimeout`.
    if ($ctx->isShuttingDown()) {
        echo "Shutdown requested, finishing early\n";

        return ['processedAt' => date('c'), 'partial' => true];
    }

    echo "Job {$ctx->jobId} processed successfully\n";
    
    // Return result
    return [
        'processedAt' => date('c'),
        'success' => true,
    ];
});

// Start the worker (blocking)
$worker->start();
