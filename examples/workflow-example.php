<?php

declare(strict_types=1);

/**
 * Workflow example for the Spooled PHP SDK.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

// Create client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
));

// Create a workflow for order processing
$workflow = $client->workflows->create([
    'name' => 'order-processing-' . time(),
    'description' => 'Process an order through validation, payment, and fulfillment',
    'jobs' => [
        [
            'key' => 'validate-order',
            'queue' => 'orders',
            'payload' => [
                'orderId' => 'ORD-12345',
                'step' => 'validate',
            ],
        ],
        [
            'key' => 'process-payment',
            'queue' => 'payments',
            'payload' => [
                'orderId' => 'ORD-12345',
                'step' => 'payment',
                'amount' => 99.99,
            ],
            'dependsOn' => ['validate-order'],
        ],
        [
            'key' => 'send-confirmation',
            'queue' => 'notifications',
            'payload' => [
                'orderId' => 'ORD-12345',
                'step' => 'confirm',
                'template' => 'order-confirmation',
            ],
            'dependsOn' => ['process-payment'],
        ],
        [
            'key' => 'fulfill-order',
            'queue' => 'fulfillment',
            'payload' => [
                'orderId' => 'ORD-12345',
                'step' => 'fulfill',
            ],
            'dependsOn' => ['process-payment'],
        ],
        [
            'key' => 'send-shipping-notification',
            'queue' => 'notifications',
            'payload' => [
                'orderId' => 'ORD-12345',
                'step' => 'shipping',
                'template' => 'shipping-notification',
            ],
            'dependsOn' => ['fulfill-order'],
        ],
    ],
]);

echo "Created workflow: {$workflow->id}\n";
echo "Name: {$workflow->name}\n";
echo "Status: {$workflow->status}\n";
echo "Total jobs: {$workflow->totalJobs}\n";

// List jobs in the workflow
echo "\nWorkflow jobs:\n";
$jobs = $client->workflows->jobs->list($workflow->id);
foreach ($jobs as $job) {
    $deps = !empty($job->dependsOn) ? ' (depends on: ' . implode(', ', $job->dependsOn) . ')' : '';
    echo "  - {$job->key}: {$job->status}{$deps}\n";
}

// Monitor workflow progress
echo "\nMonitoring workflow progress...\n";
for ($i = 0; $i < 10; $i++) {
    sleep(1);
    $status = $client->workflows->get($workflow->id);
    echo "  Status: {$status->status} (completed: {$status->completedJobs}/{$status->totalJobs})\n";

    if ($status->status === 'completed' || $status->status === 'failed' || $status->status === 'cancelled') {
        break;
    }
}

// Final status
$final = $client->workflows->get($workflow->id);
echo "\nFinal workflow status: {$final->status}\n";
