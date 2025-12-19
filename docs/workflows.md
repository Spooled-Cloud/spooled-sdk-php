# Workflows Guide

Workflows let you orchestrate multiple jobs with dependencies, creating directed acyclic graphs (DAGs) of job execution.

## Basic Workflow

```php
use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

$client = new SpooledClient(
    ClientOptions::fromArray(['apiKey' => 'sk_live_...'])
);

// Create a workflow
$workflow = $client->workflows->create([
    'name' => 'Order Processing',
    'description' => 'Process customer order: validate, charge, fulfill',
]);

echo "Workflow ID: {$workflow->id}\n";
```

## Adding Jobs with Dependencies

```php
// Job 1: Validate order (no dependencies - runs first)
$validateJob = $client->workflows->addJob($workflow->id, [
    'queue' => 'orders',
    'payload' => [
        'action' => 'validate',
        'orderId' => 'order-123',
    ],
    'name' => 'validate-order',
]);

// Job 2: Charge payment (depends on validation)
$chargeJob = $client->workflows->addJob($workflow->id, [
    'queue' => 'payments',
    'payload' => [
        'action' => 'charge',
        'orderId' => 'order-123',
        'amount' => 99.99,
    ],
    'name' => 'charge-payment',
    'dependencies' => [$validateJob->id],
]);

// Job 3: Send confirmation (depends on payment)
$emailJob = $client->workflows->addJob($workflow->id, [
    'queue' => 'emails',
    'payload' => [
        'action' => 'send_confirmation',
        'orderId' => 'order-123',
    ],
    'name' => 'send-confirmation',
    'dependencies' => [$chargeJob->id],
]);

// Job 4: Update inventory (depends on payment, runs parallel to email)
$inventoryJob = $client->workflows->addJob($workflow->id, [
    'queue' => 'inventory',
    'payload' => [
        'action' => 'update',
        'orderId' => 'order-123',
    ],
    'name' => 'update-inventory',
    'dependencies' => [$chargeJob->id],
]);
```

## Workflow Visualization

The above creates this DAG:

```
          ┌─────────────┐
          │  Validate   │
          │   Order     │
          └──────┬──────┘
                 │
          ┌──────▼──────┐
          │   Charge    │
          │  Payment    │
          └──────┬──────┘
                 │
        ┌────────┴────────┐
        │                 │
 ┌──────▼──────┐   ┌──────▼──────┐
 │    Send     │   │   Update    │
 │ Confirmation│   │  Inventory  │
 └─────────────┘   └─────────────┘
```

## Starting a Workflow

```php
// Start the workflow (begins executing jobs)
$client->workflows->start($workflow->id);

// Or create and start in one call
$workflow = $client->workflows->create([
    'name' => 'Quick Process',
    'autoStart' => true,  // Starts immediately
]);
```

## Workflow Status

```php
// Get workflow status
$status = $client->workflows->get($workflow->id);

echo "Status: {$status->status}\n";      // 'pending', 'running', 'completed', 'failed'
echo "Progress: {$status->progress}%\n"; // 0-100

// Detailed job status
foreach ($status->jobs as $job) {
    echo "{$job['name']}: {$job['status']}\n";
}
```

## Cancelling a Workflow

```php
// Cancel the entire workflow
$client->workflows->cancel($workflow->id);
// All pending jobs are cancelled; running jobs complete or fail
```

## Retrying Failed Workflows

```php
// If a workflow fails, you can retry it
$client->workflows->retry($workflow->id);
// Only failed jobs are retried; completed jobs are skipped
```

## Listing Workflows

```php
// List all workflows
$workflows = $client->workflows->list([
    'status' => 'running',  // Optional: filter by status
    'limit' => 50,
]);

foreach ($workflows->data as $workflow) {
    echo "{$workflow->id}: {$workflow->name} - {$workflow->status}\n";
}
```

## Complex DAG Example

Here's a more complex e-commerce order processing workflow:

```php
<?php

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

$client = new SpooledClient(
    ClientOptions::fromArray(['apiKey' => getenv('SPOOLED_API_KEY')])
);

function createOrderWorkflow(SpooledClient $client, array $order): string
{
    $workflow = $client->workflows->create([
        'name' => "Order #{$order['id']}",
        'description' => "Process order for {$order['customer']['email']}",
    ]);

    // Stage 1: Validation (parallel)
    $validateOrder = $client->workflows->addJob($workflow->id, [
        'queue' => 'validation',
        'payload' => ['action' => 'validate_order', 'order' => $order],
        'name' => 'validate-order',
    ]);

    $checkInventory = $client->workflows->addJob($workflow->id, [
        'queue' => 'validation',
        'payload' => ['action' => 'check_inventory', 'items' => $order['items']],
        'name' => 'check-inventory',
    ]);

    $fraudCheck = $client->workflows->addJob($workflow->id, [
        'queue' => 'validation',
        'payload' => ['action' => 'fraud_check', 'customer' => $order['customer']],
        'name' => 'fraud-check',
    ]);

    // Stage 2: Payment (depends on all validations)
    $processPayment = $client->workflows->addJob($workflow->id, [
        'queue' => 'payments',
        'payload' => [
            'action' => 'process',
            'amount' => $order['total'],
            'paymentMethod' => $order['payment'],
        ],
        'name' => 'process-payment',
        'dependencies' => [$validateOrder->id, $checkInventory->id, $fraudCheck->id],
    ]);

    // Stage 3: Fulfillment (parallel, depends on payment)
    $reserveInventory = $client->workflows->addJob($workflow->id, [
        'queue' => 'inventory',
        'payload' => ['action' => 'reserve', 'items' => $order['items']],
        'name' => 'reserve-inventory',
        'dependencies' => [$processPayment->id],
    ]);

    $createShipping = $client->workflows->addJob($workflow->id, [
        'queue' => 'shipping',
        'payload' => ['action' => 'create_label', 'address' => $order['shipping']],
        'name' => 'create-shipping',
        'dependencies' => [$processPayment->id],
    ]);

    // Stage 4: Notifications (depends on fulfillment)
    $sendConfirmation = $client->workflows->addJob($workflow->id, [
        'queue' => 'emails',
        'payload' => [
            'template' => 'order_confirmation',
            'to' => $order['customer']['email'],
            'data' => $order,
        ],
        'name' => 'send-confirmation',
        'dependencies' => [$reserveInventory->id, $createShipping->id],
    ]);

    $sendSms = $client->workflows->addJob($workflow->id, [
        'queue' => 'sms',
        'payload' => [
            'to' => $order['customer']['phone'],
            'message' => "Order #{$order['id']} confirmed!",
        ],
        'name' => 'send-sms',
        'dependencies' => [$processPayment->id],
    ]);

    // Stage 5: Analytics (depends on all, fire-and-forget)
    $trackOrder = $client->workflows->addJob($workflow->id, [
        'queue' => 'analytics',
        'payload' => ['event' => 'order_completed', 'order' => $order],
        'name' => 'track-analytics',
        'dependencies' => [$sendConfirmation->id],
    ]);

    // Start the workflow
    $client->workflows->start($workflow->id);

    return $workflow->id;
}

// Usage
$order = [
    'id' => 'ORD-12345',
    'customer' => [
        'email' => 'customer@example.com',
        'phone' => '+1234567890',
    ],
    'items' => [
        ['sku' => 'PROD-001', 'qty' => 2],
        ['sku' => 'PROD-002', 'qty' => 1],
    ],
    'total' => 149.99,
    'payment' => ['method' => 'card', 'token' => 'tok_xxx'],
    'shipping' => [
        'name' => 'John Doe',
        'address' => '123 Main St',
        'city' => 'New York',
        'zip' => '10001',
    ],
];

$workflowId = createOrderWorkflow($client, $order);
echo "Created workflow: {$workflowId}\n";
```

## Workflow Events

Monitor workflow progress with events:

```php
// Using SSE to monitor workflow
$realtime = $client->realtime();

$realtime->subscribe("workflow:{$workflow->id}", function (array $event) {
    echo "Workflow event: {$event['type']}\n";
    
    switch ($event['type']) {
        case 'workflow:job_completed':
            echo "  Job {$event['jobId']} completed\n";
            break;
        case 'workflow:job_failed':
            echo "  Job {$event['jobId']} failed: {$event['error']}\n";
            break;
        case 'workflow:completed':
            echo "  Workflow completed!\n";
            break;
        case 'workflow:failed':
            echo "  Workflow failed!\n";
            break;
    }
});
```

## Workflow Options

```php
$workflow = $client->workflows->create([
    'name' => 'My Workflow',
    'description' => 'Optional description',
    
    // Timeout for entire workflow (seconds)
    'timeout' => 3600,  // 1 hour
    
    // What to do when a job fails
    'onJobFailure' => 'cancel',  // 'cancel' | 'continue' | 'pause'
    
    // Metadata
    'metadata' => [
        'source' => 'api',
        'triggeredBy' => 'user-123',
    ],
]);
```

## Error Handling

```php
use Spooled\Errors\NotFoundError;
use Spooled\Errors\ValidationError;

try {
    // Create with invalid circular dependency
    $client->workflows->addJob($workflow->id, [
        'queue' => 'test',
        'payload' => [],
        'dependencies' => ['non-existent-job-id'],
    ]);
} catch (ValidationError $e) {
    echo "Validation error: {$e->getMessage()}\n";
} catch (NotFoundError $e) {
    echo "Workflow not found: {$e->getMessage()}\n";
}
```

## Best Practices

1. **Keep jobs idempotent**: Jobs may be retried, so they should handle re-execution gracefully.

2. **Use meaningful names**: Give each job a descriptive name for easier debugging.

3. **Handle partial failures**: Consider what happens if some jobs complete but others fail.

4. **Set appropriate timeouts**: Both per-job and workflow-level timeouts help prevent stuck workflows.

5. **Monitor progress**: Use real-time events or polling to track workflow execution.

6. **Design for failure**: Plan recovery strategies for each stage of your workflow.
