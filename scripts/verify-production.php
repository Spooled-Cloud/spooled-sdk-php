#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Interactive production verification script.
 *
 * This script walks through a series of interactive tests to verify
 * the SDK works correctly with a production or staging environment.
 *
 * Usage:
 *   php scripts/verify-production.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\Config\ClientOptions;
use Spooled\SpooledClient;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerConfig;

echo "Spooled PHP SDK - Production Verification\n";
echo "==========================================\n\n";

// Helper function to prompt for input
function prompt(string $message, ?string $default = null): string
{
    $defaultHint = $default !== null ? " [{$default}]" : '';
    echo "{$message}{$defaultHint}: ";
    $input = trim(fgets(STDIN) ?: '');

    return $input !== '' ? $input : ($default ?? '');
}

// Helper function to prompt for yes/no
function confirm(string $message, bool $default = true): bool
{
    $hint = $default ? '[Y/n]' : '[y/N]';
    echo "{$message} {$hint}: ";
    $input = strtolower(trim(fgets(STDIN) ?: ''));
    if ($input === '') {
        return $default;
    }

    return in_array($input, ['y', 'yes'], true);
}

// Step 1: Get configuration
echo "Step 1: Configuration\n";
echo "---------------------\n";

$apiKey = prompt('Enter your API key');
if (empty($apiKey)) {
    fwrite(STDERR, "Error: API key is required\n");
    exit(1);
}

$baseUrl = prompt('Enter base URL', 'https://api.spooled.cloud');

echo "\n";

// Step 2: Create client and verify connection
echo "Step 2: Verify Connection\n";
echo "-------------------------\n";

$client = new SpooledClient(new ClientOptions(
    apiKey: $apiKey,
    baseUrl: $baseUrl,
));

echo 'Checking health... ';

try {
    $health = $client->health->check();
    if ($health->isHealthy()) {
        echo "✅ Healthy\n";
    } else {
        echo "⚠️  Status: {$health->status}\n";
    }
} catch (\Throwable $e) {
    echo "❌ Failed: {$e->getMessage()}\n";
    exit(1);
}

echo 'Validating API key... ';

try {
    $validation = $client->auth->validate();
    if ($validation->valid) {
        echo "✅ Valid\n";
    } else {
        echo "❌ Invalid\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo "❌ Failed: {$e->getMessage()}\n";
    exit(1);
}

echo 'Getting user info... ';

try {
    $user = $client->auth->me();
    echo "✅ {$user->email}\n";
} catch (\Throwable $e) {
    echo "❌ Failed: {$e->getMessage()}\n";
}

echo "\n";

// Step 3: Test job creation
echo "Step 3: Test Job Creation\n";
echo "-------------------------\n";

$testQueue = 'php-verify-' . substr(md5((string) microtime(true)), 0, 8);

echo 'Creating test job... ';

try {
    $job = $client->jobs->create([
        'queue' => $testQueue,
        'payload' => [
            'test' => true,
            'source' => 'php-verify',
            'timestamp' => time(),
        ],
    ]);
    echo "✅ Created job: {$job->id}\n";
    $testJobId = $job->id;
} catch (\Throwable $e) {
    echo "❌ Failed: {$e->getMessage()}\n";
    exit(1);
}

echo 'Retrieving job... ';

try {
    $retrieved = $client->jobs->get($testJobId);
    echo "✅ Status: {$retrieved->status}\n";
} catch (\Throwable $e) {
    echo "❌ Failed: {$e->getMessage()}\n";
}

echo "\n";

// Step 4: Test worker
echo "Step 4: Test Worker\n";
echo "-------------------\n";

if (confirm('Run worker test? (will process the test job)')) {
    echo "Starting worker for 10 seconds...\n";

    $worker = new SpooledWorker($client, new WorkerConfig(
        queues: [$testQueue],
        concurrency: 1,
        pollInterval: 500,
    ));

    $processed = false;
    $worker->on('job:completed', function () use (&$processed): void {
        $processed = true;
        echo "  ✅ Job processed!\n";
    });

    // Run worker in background with timeout
    $startTime = time();
    $timeout = 10;

    while (!$processed && (time() - $startTime) < $timeout) {
        try {
            $job = $client->jobs->claim([$testQueue], ['workerId' => 'verify-worker']);
            if ($job !== null) {
                echo "  Processing job {$job->id}...\n";
                $client->jobs->complete($job->id, ['verified' => true]);
                $processed = true;
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        usleep(500000); // 500ms
    }

    if (!$processed) {
        echo "  ⚠️  No jobs processed (job may have been processed already)\n";
    }
}

echo "\n";

// Step 5: Test webhook (optional)
echo "Step 5: Test Webhook (Optional)\n";
echo "-------------------------------\n";

if (confirm('Create test webhook?', false)) {
    $webhookUrl = prompt('Enter webhook URL (e.g., ngrok tunnel)');

    if (!empty($webhookUrl)) {
        echo 'Creating webhook... ';

        try {
            $webhook = $client->webhooks->create([
                'name' => 'php-verify-webhook',
                'url' => $webhookUrl,
                'events' => ['job.completed', 'job.failed'],
            ]);
            echo "✅ Created: {$webhook->id}\n";

            echo 'Testing webhook... ';

            try {
                $delivery = $client->webhooks->test($webhook->id);
                echo "✅ Test delivery sent\n";
            } catch (\Throwable $e) {
                echo "❌ Failed: {$e->getMessage()}\n";
            }

            if (confirm('Delete test webhook?')) {
                $client->webhooks->delete($webhook->id);
                echo "Webhook deleted.\n";
            }
        } catch (\Throwable $e) {
            echo "❌ Failed: {$e->getMessage()}\n";
        }
    }
}

echo "\n";

// Step 6: Cleanup
echo "Step 6: Cleanup\n";
echo "---------------\n";

if (confirm('Purge test queue?')) {
    echo "Purging queue {$testQueue}... ";

    try {
        $client->queues->purge($testQueue);
        echo "✅ Done\n";
    } catch (\Throwable $e) {
        echo "❌ Failed: {$e->getMessage()}\n";
    }
}

echo "\n";
echo "==========================================\n";
echo "Verification complete!\n";
echo "==========================================\n";
