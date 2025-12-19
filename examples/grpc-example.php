<?php

declare(strict_types=1);

/**
 * gRPC transport example for the Spooled PHP SDK.
 *
 * Requirements:
 * - ext-grpc (pecl install grpc)
 * - ext-protobuf (pecl install protobuf)
 * - composer require grpc/grpc google/protobuf
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Grpc\SpooledGrpcClient;
use Spooled\Grpc\GrpcOptions;

// Check if gRPC is available
if (!SpooledGrpcClient::isAvailable()) {
    echo "gRPC support requires ext-grpc and ext-protobuf extensions.\n";
    echo "Install with: pecl install grpc protobuf\n";
    exit(1);
}

// Method 1: Create gRPC client via main client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
    baseUrl: getenv('BASE_URL') ?: 'https://api.spooled.cloud',
    grpcAddress: getenv('GRPC_ADDRESS') ?: 'grpc.spooled.cloud:443',
));

try {
    // Get gRPC client (lazy initialized)
    $grpc = $client->grpc();

    // Wait for connection
    $grpc->waitForReady();
    echo "gRPC connected!\n";

    // Enqueue via gRPC (faster than REST for high throughput)
    $result = $grpc->queue->enqueue([
        'queueName' => 'grpc-test-queue',
        'payload' => [
            'message' => 'Hello from gRPC!',
            'timestamp' => time(),
        ],
        'priority' => 5,
    ]);

    echo "Created job via gRPC: {$result['jobId']}\n";

    // Get queue stats via gRPC
    $stats = $grpc->queue->getStats([
        'queueName' => 'grpc-test-queue',
    ]);

    echo "Queue stats - pending: {$stats['pending']}, completed: {$stats['completed']}\n";

    // Close gRPC connection
    $grpc->close();
    echo "gRPC connection closed.\n";

} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}

// Method 2: Create standalone gRPC client
echo "\n--- Standalone gRPC client ---\n";

$grpcClient = new SpooledGrpcClient(new GrpcOptions(
    address: getenv('GRPC_ADDRESS') ?: 'grpc.spooled.cloud:443',
    apiKey: getenv('API_KEY') ?: 'your-api-key',
    secure: true,
));

try {
    $grpcClient->waitForReady();

    // Register a worker via gRPC
    $worker = $grpcClient->workers->register([
        'queueName' => 'grpc-test-queue',
        'hostname' => gethostname(),
        'concurrency' => 5,
    ]);

    echo "Registered worker via gRPC: {$worker['workerId']}\n";

    // Send heartbeat
    $heartbeat = $grpcClient->workers->heartbeat([
        'workerId' => $worker['workerId'],
        'currentJobs' => 0,
        'status' => 'idle',
    ]);

    echo "Heartbeat acknowledged: " . ($heartbeat['acknowledged'] ? 'yes' : 'no') . "\n";

    // Deregister worker
    $grpcClient->workers->deregister([
        'workerId' => $worker['workerId'],
    ]);

    echo "Worker deregistered.\n";

} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
} finally {
    $grpcClient->close();
}

echo "\nDone!\n";
