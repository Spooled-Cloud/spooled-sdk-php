<?php

declare(strict_types=1);

/**
 * Basic usage example for the Spooled PHP SDK.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Errors\SpooledError;

// Create client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
    baseUrl: getenv('BASE_URL') ?: 'https://api.spooled.cloud',
));

try {
    // Check health
    $health = $client->health->check();
    echo "Service health: {$health->status}\n";
    
    // Create a job
    $result = $client->jobs->create([
        'queue' => 'example-queue',
        'payload' => [
            'message' => 'Hello from PHP SDK!',
            'timestamp' => time(),
        ],
        'priority' => 5,
        'maxRetries' => 3,
    ]);
    
    echo "Created job: {$result->id}\n";
    
    // Get job status
    $job = $client->jobs->get($result->id);
    echo "Job status: {$job->status}\n";
    echo "Queue: {$job->queueName}\n";
    echo "Priority: {$job->priority}\n";
    
    // List jobs in queue
    $jobs = $client->jobs->list(['queue' => 'example-queue', 'limit' => 5]);
    echo "Jobs in queue: " . count($jobs) . "\n";
    
    // Get queue stats
    $stats = $client->queues->getStats('example-queue');
    echo "Queue stats - pending: {$stats->pending}, completed: {$stats->completed}\n";
    
    // Create a job and get full details immediately
    $jobWithDetails = $client->jobs->createAndGet([
        'queue' => 'example-queue',
        'payload' => ['test' => true],
    ]);
    echo "Created and got job: {$jobWithDetails->id} (status: {$jobWithDetails->status})\n";
    
    // Get job statistics
    $jobStats = $client->jobs->getStats();
    echo "Total jobs - pending: {$jobStats->pending}, completed: {$jobStats->completed}\n";
    
    // Cancel the test job
    $client->jobs->cancel($jobWithDetails->id);
    echo "Cancelled job: {$jobWithDetails->id}\n";
    
} catch (SpooledError $e) {
    echo "Error [{$e->statusCode}]: {$e->getMessage()}\n";
    if ($e->requestId) {
        echo "Request ID: {$e->requestId}\n";
    }
    exit(1);
}
