# Resources Reference

This is a complete reference for all SDK resources and their methods.

## Jobs

### Create Job

```php
$job = $client->jobs->create([
    'queue' => 'my-queue',             // Required: target queue
    'payload' => ['data' => 'value'],  // Required: job data (array)

    // Optional
    'priority' => 5,                    // -100 to 100 (default: 0)
    'maxRetries' => 3,                  // Retry attempts (default: 3)
    'timeoutSeconds' => 300,            // Job timeout (default: 300)
    'scheduledAt' => new DateTime(),    // Delay execution
    'idempotencyKey' => 'unique-key',   // Prevent duplicates
    'tags' => ['env' => 'prod'],        // Metadata tags
]);

echo "Job ID: {$job->id}\n";
```

### Create and Get (returns full job)

```php
$job = $client->jobs->createAndGet([
    'queue' => 'my-queue',
    'payload' => ['task' => 'process'],
]);
// Returns complete Job object with all fields
```

### List Jobs

```php
$result = $client->jobs->list([
    'queue' => 'my-queue',              // Optional: filter by queue
    'status' => 'pending',              // Optional: 'pending' | 'processing' | 'completed' | 'failed' | 'cancelled'
    'limit' => 10,                      // Pagination limit
    'offset' => 0,                      // Pagination offset
]);

foreach ($result->data as $job) {
    echo "{$job->id}: {$job->status}\n";
}
```

### Get Job

```php
$job = $client->jobs->get('job_id');
// Returns: Job object with id, queueName, status, payload, priority, etc.
```

### Cancel Job

```php
$client->jobs->cancel('job_id');
```

### Retry Job

```php
$newJob = $client->jobs->retry('job_id');
echo "New Job ID: {$newJob->id}\n";
```

### Boost Priority

```php
$client->jobs->boostPriority('job_id', 10); // Add 10 to priority
```

### Get Statistics

```php
$stats = $client->jobs->getStats();
echo "Pending: {$stats->pending}\n";
echo "Processing: {$stats->processing}\n";
echo "Completed: {$stats->completed}\n";
echo "Failed: {$stats->failed}\n";
```

### Bulk Enqueue

```php
$result = $client->jobs->bulkEnqueue([
    'queueName' => 'my-queue',
    'jobs' => [
        ['payload' => ['n' => 1]],
        ['payload' => ['n' => 2], 'priority' => 10],
        ['payload' => ['n' => 3], 'maxRetries' => 5],
    ],
]);
// $result->jobIds: ['job_1', 'job_2', 'job_3']
// $result->enqueuedCount: 3
```

### Batch Status

```php
$statuses = $client->jobs->batchStatus(['job_1', 'job_2', 'job_3']);
// ['job_1' => 'completed', 'job_2' => 'processing', 'job_3' => 'pending']
```

### Claim Jobs (for workers)

```php
$result = $client->jobs->claim([
    'queueName' => 'my-queue',
    'workerId' => 'worker-1',
    'limit' => 10,
    'leaseDurationSecs' => 300,
]);
// $result->jobs: array of Job objects
// $result->claimedCount: int
```

### Complete Job

```php
$client->jobs->complete('job_id', [
    'workerId' => 'worker-1',
    'result' => ['success' => true],
]);
```

### Fail Job

```php
$client->jobs->fail('job_id', [
    'workerId' => 'worker-1',
    'error' => 'Something went wrong',
]);
```

### Heartbeat

```php
$client->jobs->heartbeat('job_id', [
    'workerId' => 'worker-1',
    'leaseDurationSecs' => 300,
]);
```

### Dead Letter Queue

```php
// List DLQ jobs
$dlqJobs = $client->dlq->list([
    'queue' => 'my-queue',
    'limit' => 50,
]);

// Retry specific DLQ jobs
$client->dlq->retry([
    'jobIds' => ['job_1', 'job_2'],
]);

// Retry all DLQ jobs for a queue
$client->dlq->retryAll('my-queue');

// Purge DLQ
$client->dlq->purge('my-queue', true); // true = confirm
```

---

## Queues

### List Queues

```php
$queues = $client->queues->list();
foreach ($queues->data as $queue) {
    echo "{$queue->queueName}: {$queue->enabled}\n";
}
```

### Get Queue Config

```php
$config = $client->queues->get('my-queue');
```

### Update Queue Config

```php
$client->queues->updateConfig('my-queue', [
    'maxRetries' => 5,
    'defaultTimeout' => 600,
    'rateLimit' => 100,
    'enabled' => true,
]);
```

### Get Queue Stats

```php
$stats = $client->queues->getStats('my-queue');
echo "Pending: {$stats->pending}\n";
echo "Processing: {$stats->processing}\n";
```

### Pause Queue

```php
$client->queues->pause('my-queue', 'Maintenance window');
```

### Resume Queue

```php
$client->queues->resume('my-queue');
```

---

## Schedules

### Create Schedule

```php
$schedule = $client->schedules->create([
    'name' => 'Daily Report',
    'cronExpression' => '0 0 9 * * *',    // 6-field cron (with seconds)
    'timezone' => 'America/New_York',
    'queueName' => 'reports',
    'payloadTemplate' => ['type' => 'daily'],
    'enabled' => true,
]);
```

### List Schedules

```php
$schedules = $client->schedules->list();
```

### Get Schedule

```php
$schedule = $client->schedules->get('schedule_id');
```

### Update Schedule

```php
$client->schedules->update('schedule_id', [
    'cronExpression' => '0 0 8 * * *',
    'enabled' => false,
]);
```

### Delete Schedule

```php
$client->schedules->delete('schedule_id');
```

### Pause/Resume

```php
$client->schedules->pause('schedule_id');
$client->schedules->resume('schedule_id');
```

### Trigger Manually

```php
$result = $client->schedules->trigger('schedule_id');
echo "Job ID: {$result->jobId}\n";
```

### Get History

```php
$runs = $client->schedules->getHistory('schedule_id', 10);
// Last 10 executions
```

---

## Workflows

### Create Workflow

```php
$workflow = $client->workflows->create([
    'name' => 'ETL Pipeline',
    'description' => 'Extract, transform, load data',
]);
```

### Add Job to Workflow

```php
$job = $client->workflows->addJob($workflow->id, [
    'queue' => 'etl',
    'payload' => ['step' => 'extract'],
    'name' => 'extract-data',
    'dependencies' => [],  // No dependencies - runs first
]);

$transformJob = $client->workflows->addJob($workflow->id, [
    'queue' => 'etl',
    'payload' => ['step' => 'transform'],
    'name' => 'transform-data',
    'dependencies' => [$job->id],
]);
```

### Start Workflow

```php
$client->workflows->start($workflow->id);
```

### Get Workflow Status

```php
$status = $client->workflows->get($workflow->id);
echo "Status: {$status->status}\n";
echo "Progress: {$status->progress}%\n";
```

### Cancel Workflow

```php
$client->workflows->cancel($workflow->id);
```

### Retry Failed Workflow

```php
$client->workflows->retry($workflow->id);
```

### List Workflows

```php
$workflows = $client->workflows->list([
    'status' => 'running',
    'limit' => 50,
]);
```

---

## Webhooks (Outgoing)

### Create Webhook

```php
$webhook = $client->webhooks->create([
    'name' => 'Slack Notifications',
    'url' => 'https://hooks.slack.com/...',
    'events' => ['job.completed', 'job.failed'],
    'secret' => 'hmac-secret',
    'enabled' => true,
]);
```

### List Webhooks

```php
$webhooks = $client->webhooks->list();
```

### Get/Update/Delete

```php
$wh = $client->webhooks->get('webhook_id');
$client->webhooks->update('webhook_id', ['enabled' => false]);
$client->webhooks->delete('webhook_id');
```

### Test Webhook

```php
$result = $client->webhooks->test('webhook_id');
echo "Success: " . ($result->success ? 'yes' : 'no') . "\n";
```

### Get Deliveries

```php
$deliveries = $client->webhooks->getDeliveries('webhook_id', ['limit' => 50]);
```

### Retry Delivery

```php
$result = $client->webhooks->retryDelivery('webhook_id', 'delivery_id');
```

---

## Workers

### List Workers

```php
$workers = $client->workers->list();
```

### Get Worker

```php
$worker = $client->workers->get('worker_id');
```

### Register Worker

```php
$registration = $client->workers->register([
    'queueName' => 'my-queue',
    'hostname' => gethostname(),
    'workerType' => 'php',
    'maxConcurrency' => 10,
    'metadata' => ['version' => '1.0.0'],
]);
// $registration->id
// $registration->leaseDurationSecs
// $registration->heartbeatIntervalSecs
```

### Heartbeat

```php
$client->workers->heartbeat('worker_id', [
    'currentJobs' => 5,
    'status' => 'healthy',
]);
```

### Deregister

```php
$client->workers->deregister('worker_id');
```

---

## API Keys

### List Keys

```php
$keys = $client->apiKeys->list();
// Keys are masked (only last 4 chars shown)
```

### Create Key

```php
$result = $client->apiKeys->create([
    'name' => 'Production API Key',
    'queues' => ['queue-1', 'queue-2'],  // Optional: restrict to queues
    'rateLimit' => 1000,
]);
// IMPORTANT: $result->key is only shown once!
```

### Update Key

```php
$client->apiKeys->update('key_id', [
    'name' => 'Updated Name',
    'rateLimit' => 2000,
]);
```

### Revoke Key

```php
$client->apiKeys->revoke('key_id');
```

---

## Organizations

### Create Organization

```php
$result = $client->organizations->create([
    'name' => 'My Company',
    'slug' => 'my-company',
    'billingEmail' => 'billing@company.com',
]);
// $result->organization
// $result->apiKey (full key - save this!)
```

### Get Usage

```php
$usage = $client->organizations->getUsage();
echo "Plan: {$usage->planDisplayName}\n";
echo "Jobs Today: {$usage->usage->jobsToday}\n";
```

### List Organizations

```php
$orgs = $client->organizations->list();
```

### Check Slug Availability

```php
$result = $client->organizations->checkSlug('my-company');
echo "Available: " . ($result->available ? 'yes' : 'no') . "\n";
echo "Suggestion: {$result->suggestion}\n";
```

### Generate Slug

```php
$result = $client->organizations->generateSlug('My Company Name');
echo "Slug: {$result->slug}\n";
```

---

## Billing

### Get Status

```php
$status = $client->billing->getStatus();
echo "Plan: {$status->planTier}\n";
```

### Create Portal Session

```php
$portal = $client->billing->createPortal([
    'returnUrl' => 'https://yourapp.com/billing',
]);
// Redirect user to $portal->url
```

---

## Authentication

### Login with API Key

```php
$result = $client->auth->login('sk_live_...');
// $result->accessToken
// $result->refreshToken

// Use JWT for subsequent requests
$jwtClient = new SpooledClient(
    ClientOptions::fromArray(['accessToken' => $result->accessToken])
);
```

### Validate Token

```php
$result = $client->auth->validate(['token' => $accessToken]);
echo "Valid: " . ($result->valid ? 'yes' : 'no') . "\n";
```

### Refresh Token

```php
$result = $client->auth->refresh(['refreshToken' => $refreshToken]);
$newAccessToken = $result->accessToken;
```

### Get Current User

```php
$user = $client->auth->me(); // Requires JWT token
echo "Organization: {$user->organizationId}\n";
```

### Logout

```php
$client->auth->logout();
```

---

## Dashboard

### Get Dashboard Data

```php
$dashboard = $client->dashboard->get();
// $dashboard->system
// $dashboard->jobs
// $dashboard->queues
// $dashboard->workers
// $dashboard->recentActivity
```

---

## Health & Metrics

### Health Check

```php
$health = $client->health->get();
echo "Status: {$health->status}\n";
echo "Database: {$health->database}\n";
```

### Readiness

```php
$ready = $client->health->readiness();
echo "Ready: " . ($ready ? 'yes' : 'no') . "\n";
```

### Prometheus Metrics

```php
$metrics = $client->metrics->get();
// Raw Prometheus metrics text
```

---

## Admin API

Requires `adminKey` in client config.

### List Organizations

```php
$orgs = $client->admin->listOrganizations([
    'planTier' => 'pro',
    'limit' => 10,
]);
```

### Get Organization

```php
$org = $client->admin->getOrganization('org_id');
```

### Update Organization

```php
$client->admin->updateOrganization('org_id', [
    'planTier' => 'enterprise',
]);
```

### Get Admin Stats

```php
$stats = $client->admin->getStats();
```

### Create API Key for Organization

```php
$result = $client->admin->createApiKey([
    'organizationId' => 'org_id',
    'name' => 'Admin-created key',
]);
```

---

## Webhook Ingestion (Incoming)

For receiving webhooks from external services.

### Custom Webhook

```php
$client->ingest->custom([
    'queueName' => 'custom_events',
    'eventType' => 'custom.event',
    'payload' => ['data' => 'value'],
]);
```

### GitHub Webhook

```php
$client->ingest->github(
    $orgId,
    $rawRequestBody,
    $signatureHeader  // X-Hub-Signature-256
);
```

### Stripe Webhook

```php
$client->ingest->stripe(
    $orgId,
    $rawRequestBody,
    $signatureHeader  // Stripe-Signature
);
```

