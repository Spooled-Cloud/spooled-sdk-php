# Workflows Guide

Workflows create a directed acyclic graph (DAG) of jobs. In the current PHP SDK, submit the complete job graph in `workflows->create()`; there are no `addJob()` or `start()` methods.

## Create a Workflow

```php
<?php

use Spooled\Config\ClientOptions;
use Spooled\SpooledClient;

$client = new SpooledClient(new ClientOptions(apiKey: 'sp_live_...'));

$workflow = $client->workflows->create([
    'name' => 'Order Processing',
    'description' => 'Validate, charge, then fulfill an order',
    'jobs' => [
        [
            'key' => 'validate',
            'queue' => 'orders',
            'payload' => ['action' => 'validate', 'orderId' => 'ORD-123'],
        ],
        [
            'key' => 'charge',
            'queue' => 'payments',
            'payload' => ['action' => 'charge', 'orderId' => 'ORD-123'],
            'dependsOn' => ['validate'],
        ],
        [
            'key' => 'email',
            'queue' => 'notifications',
            'payload' => ['action' => 'confirm', 'orderId' => 'ORD-123'],
            'dependsOn' => ['charge'],
        ],
        [
            'key' => 'fulfill',
            'queue' => 'fulfillment',
            'payload' => ['action' => 'ship', 'orderId' => 'ORD-123'],
            'dependsOn' => ['charge'],
        ],
    ],
]);

echo "Workflow: {$workflow->id} ({$workflow->status})\n";
```

Dependencies refer to job `key` values in the same request. The graph above runs `email` and `fulfill` after `charge` succeeds.

## Read Status and Jobs

```php
$current = $client->workflows->get($workflow->id);
echo "{$current->completedJobs}/{$current->totalJobs} complete\n";

$jobs = $client->workflows->jobs->list($workflow->id);
foreach ($jobs as $job) {
    echo "{$job->key}: {$job->status}\n";
}

$statuses = $client->workflows->jobs->getStatus($workflow->id);
$job = $client->workflows->jobs->get($workflow->id, $jobId);
```

## List, Cancel, Retry, and Delete

```php
$workflows = $client->workflows->list([
    'status' => 'running',
    'limit' => 50,
]);

$cancelled = $client->workflows->cancel($workflow->id);
$retried = $client->workflows->retry($workflow->id); // failed workflows only
$client->workflows->delete($workflow->id);
```

Retry resets failed/dead-letter jobs to pending and resumes a failed workflow. Completed jobs are not recreated.

## Job Dependency Operations

The workflow-jobs sub-resource can inspect and add dependencies for an existing job:

```php
$withDependencies = $client->workflows->jobs->getDependencies($jobId);

$result = $client->workflows->jobs->addDependencies($jobId, [
    'dependsOnJobIds' => [$upstreamJobId],
]);
```

Use this API only with valid job IDs and an acyclic dependency relationship.

## Monitoring

Poll `workflows->get()` or use the SDK's realtime client and register supported dotted event handlers. Do not use the old topic-style `subscribe("workflow:...")` API; it is not part of the current realtime surface.

## Best Practices

1. Keep every job idempotent because retries and lease recovery can re-execute work.
2. Use stable, descriptive keys and reference those keys in `dependsOn`.
3. Keep payloads limited to data needed by each stage.
4. Handle partial failure explicitly and retry only failed workflows.
5. Poll or subscribe to supported realtime event types for operational visibility.