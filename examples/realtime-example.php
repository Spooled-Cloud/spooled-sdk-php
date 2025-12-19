<?php

declare(strict_types=1);

/**
 * Realtime events example for the Spooled PHP SDK.
 *
 * This demonstrates subscribing to real-time job and queue events
 * using Server-Sent Events (SSE) or WebSocket.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Realtime\SseClient;
use Spooled\Realtime\WebSocketClient;

// Create client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
    baseUrl: getenv('BASE_URL') ?: 'https://api.spooled.cloud',
));

echo "=== Spooled Realtime Events Example ===\n\n";

// Method 1: Use the unified realtime client (via SpooledClient)
echo "Method 1: Unified realtime client\n";
echo str_repeat('-', 40) . "\n";

$realtime = $client->realtime();

// Check what transport is available
if ($realtime->isWebSocketAvailable()) {
    echo "WebSocket transport available (preferred)\n";
} else {
    echo "Using SSE transport (WebSocket not available)\n";
}

// Method 2: Direct SSE client (always available)
echo "\nMethod 2: Direct SSE Client\n";
echo str_repeat('-', 40) . "\n";

$sse = new SseClient(
    baseUrl: getenv('BASE_URL') ?: 'https://api.spooled.cloud',
    apiKey: getenv('API_KEY') ?: 'your-api-key',
);

// Subscribe to all events
$sse->subscribe(function (array $event): void {
    $type = $event['type'] ?? 'unknown';
    $data = $event['data'] ?? [];

    echo "[{$type}] ";

    if (is_array($data)) {
        $jobId = $data['jobId'] ?? $data['job_id'] ?? 'N/A';
        $queue = $data['queueName'] ?? $data['queue_name'] ?? 'N/A';
        echo "Job: {$jobId}, Queue: {$queue}";
    } else {
        echo json_encode($data);
    }

    echo "\n";
});

// Subscribe to specific event types
$sse->on('job.created', function (array $event): void {
    echo "🆕 New job created!\n";
});

$sse->on('job.completed', function (array $event): void {
    echo "✅ Job completed!\n";
});

$sse->on('job.failed', function (array $event): void {
    echo "❌ Job failed!\n";
});

// Subscribe to a specific queue
$sse->subscribeToQueue('my-queue', function (array $event): void {
    echo "📬 Event on my-queue: {$event['type']}\n";
});

// Subscribe to a specific job
// $sse->subscribeToJob('job-id-here', function (array $event): void {
//     echo "📌 Event for specific job: {$event['type']}\n";
// });

// Connection lifecycle events
$sse->on('connected', function (array $data): void {
    echo "🔗 Connected to SSE stream\n";
});

$sse->on('reconnecting', function (array $data): void {
    $delay = $data['delay'] ?? 0;
    $attempt = $data['attempt'] ?? 0;
    echo "🔄 Reconnecting (attempt {$attempt}) in {$delay}ms...\n";
});

// Handle shutdown gracefully
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, fn () => $sse->stop());
    pcntl_signal(SIGINT, fn () => $sse->stop());
    echo "\nPress Ctrl+C to stop listening.\n\n";
}

// Method 3: WebSocket client (if available)
if (WebSocketClient::isAvailable()) {
    echo "\nMethod 3: WebSocket Client (available)\n";
    echo str_repeat('-', 40) . "\n";

    $ws = new WebSocketClient(
        wsUrl: str_replace('http', 'ws', getenv('BASE_URL') ?: 'wss://api.spooled.cloud') . '/api/v1/ws',
        apiKey: getenv('API_KEY') ?: 'your-api-key',
    );

    // WebSocket allows bidirectional communication
    $ws->on('message', function (array $event): void {
        echo "[WS] {$event['type']}: " . json_encode($event['data'] ?? []) . "\n";
    });

    // WebSocket-specific subscriptions
    $ws->subscribeToQueue('my-queue');
    // $ws->subscribeToJob('job-id');

    // Note: Don't start both SSE and WS - pick one
    echo "WebSocket client configured (not starting - using SSE for this demo)\n";
}

// Create a test job to see events
echo "\nCreating a test job to trigger events...\n";

try {
    $testJob = $client->jobs->create([
        'queue' => 'realtime-test',
        'payload' => ['test' => true, 'timestamp' => time()],
    ]);
    echo "Created test job: {$testJob->id}\n";
    echo "Watch for events below:\n\n";
} catch (\Throwable $e) {
    echo "Could not create test job: {$e->getMessage()}\n";
}

// Start listening (blocking - will run until stopped)
echo "Listening for events (Ctrl+C to stop)...\n\n";

try {
    $sse->listen();
} catch (\Throwable $e) {
    echo "SSE error: {$e->getMessage()}\n";
}

// Cleanup
$client->close();

echo "\nDone!\n";
