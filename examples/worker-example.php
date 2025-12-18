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
));

// Register event handlers
$worker->on('started', function (array $data): void {
    echo "Worker started: {$data['workerId']}\n";
});

$worker->on('job:claimed', function (array $data): void {
    echo "Job claimed: {$data['job']->id}\n";
});

$worker->on('job:completed', function (array $data): void {
    echo "Job completed: {$data['job']->id}\n";
});

$worker->on('job:failed', function (array $data): void {
    echo "Job failed: {$data['job']->id} - {$data['error']}\n";
});

$worker->on('stopped', function (array $data): void {
    echo "Worker stopped. Completed: {$data['completedJobs']}, Failed: {$data['failedJobs']}\n";
});

// Handle shutdown signals
if (function_exists('pcntl_signal')) {
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

    // Check if we should stop
    if ($ctx->isShuttingDown()) {
        echo "Shutdown requested, stopping early\n";
        throw new \RuntimeException('Worker shutdown requested');
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
