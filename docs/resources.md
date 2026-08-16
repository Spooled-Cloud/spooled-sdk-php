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

foreach ($result->jobs as $job) {
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
// $result->succeeded: entries with index, jobId, and created
// $result->successCount: 3
// $result->failureCount: 0
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
// $result->jobs: array of ClaimedJob objects; preserve each $job->leaseId
// count($result->jobs): number claimed
```

### Complete Job

```php
$client->jobs->complete('job_id', [
    'workerId' => 'worker-1',
    'result' => ['success' => true],
    'leaseId' => $claimedJob->leaseId, // fencing token from claim, when present
]);
```

### Fail Job

```php
$client->jobs->fail('job_id', [
    'workerId' => 'worker-1',
    'error' => 'Something went wrong',
    'leaseId' => $claimedJob->leaseId, // fencing token from claim, when present
]);
```

### Heartbeat

```php
$client->jobs->heartbeat('job_id', [
    'workerId' => 'worker-1',
    'leaseDurationSecs' => 300,
    'leaseId' => $claimedJob->leaseId, // fencing token from claim, when present
]);
```

### Dead Letter Queue

```php
// List DLQ jobs
$dlqJobs = $client->jobs->dlq->list([
    'queue' => 'my-queue',
    'limit' => 50,
]);

// Retry specific jobs or a bounded queue batch
$client->jobs->dlq->retry([
    'jobIds' => ['job_1', 'job_2'],
]);
$client->jobs->dlq->retry([
    'queue' => 'my-queue',
    'limit' => 50,
]);

// Purge DLQ (confirmation is required)
$client->jobs->dlq->purge([
    'queue' => 'my-queue',
    'confirm' => true,
]);
```

---

## Queues

### List Queues

```php
$queues = $client->queues->list();
foreach ($queues->queues as $queue) {
    echo "{$queue->name}: " . ($queue->paused ? 'paused' : 'active') . "\n";
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
$runs = $client->schedules->history('schedule_id', ['limit' => 10]);
// Up to 10 executions
```

---

## Workflows

### Create Workflow

Submit the complete DAG in one call; dependencies reference job keys:

```php
$workflow = $client->workflows->create([
    'name' => 'ETL Pipeline',
    'description' => 'Extract, transform, load data',
    'jobs' => [
        ['key' => 'extract', 'queue' => 'etl', 'payload' => ['step' => 'extract']],
        [
            'key' => 'transform',
            'queue' => 'etl',
            'payload' => ['step' => 'transform'],
            'dependsOn' => ['extract'],
        ],
    ],
]);
```

There are no `addJob()` or `start()` methods; creation submits the graph. See [workflows.md](workflows.md) for dependency inspection and mutation methods.

### Get Workflow Status

```php
$status = $client->workflows->get($workflow->id);
echo "Status: {$status->status}\n";
echo "Progress: {$status->completedJobs}/{$status->totalJobs}\n";
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

`secret` is three-state on update, and null is destructive:

```php
// Omit the key: the current signing secret is kept.
$client->webhooks->update('webhook_id', ['url' => 'https://new-host/hook']);

// Explicit null: the secret is CLEARED. Deliveries then go out unsigned, with
// no X-Spooled-Signature header, and a receiver that verifies signatures will
// reject every one of them.
$client->webhooks->update('webhook_id', ['secret' => null]);

// A string replaces the secret.
$client->webhooks->update('webhook_id', ['secret' => 'new-hmac-secret']);
```

Build the params array from only the keys you mean to change. Serialising a whole webhook back, or assembling params from a form or config where an unset field becomes null, now wipes the secret.

### Auto-Disable and Recovery

After 20 consecutive failed deliveries the webhook is disabled automatically: `enabled` becomes `false` and `lastStatus` becomes `"auto_disabled"`. It receives no events until it is enabled again.

```php
$wh = $client->webhooks->get('webhook_id');

if ($wh->lastStatus === 'auto_disabled') {
    // Fix the endpoint first, then re-enable.
    $client->webhooks->enable('webhook_id');   // PUT with ['enabled' => true]
}
```

Re-enabling is charged against the plan webhook cap, so it can fail with `HTTP 429` (`RateLimitError`) and `errorCode` `"QUOTA_EXCEEDED"`.

`$wh->failureCount` counts consecutive failed *deliveries*, not individual retry attempts, so the same amount of breakage shows a number roughly 5x smaller than a per-attempt count. Any successful delivery resets it to 0, including a successful manual retry.

### Test Webhook

```php
$result = $client->webhooks->test('webhook_id');
echo "Success: " . ($result->success ? 'yes' : 'no') . "\n";
```

### Get Deliveries

```php
$deliveries = $client->webhooks->getDeliveries('webhook_id', ['limit' => 50]);
```

Delivery history is retained, not permanent. Rows are removed once they pass the plan's history retention window - free 1 day, starter 7 days, pro 30 days, enterprise 90 days - and only the newest 100 deliveries per webhook are readable. Copy anything you need to keep into your own store.

### Retry Delivery

```php
$result = $client->webhooks->retryDelivery('webhook_id', 'delivery_id');
```

The delivery must still be inside the retention window; once its row is swept there is nothing left to retry.

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
    'queueName' => 'my-queue',                     // required
    'hostname' => gethostname() ?: 'php-worker',   // required
    'maxConcurrency' => 10,                        // optional, default 5
    'workerType' => 'php',                         // optional
    'workerId' => 'billing-worker-01',             // optional, 1-128 chars [A-Za-z0-9._-]
]);
// $registration->id
// $registration->heartbeatIntervalSecs
```

A stable `workerId` makes registration an upsert, so a restarting process reuses the row it already owns, and re-registering an id you own is not charged against the plan worker cap. Use something that survives restarts (a hostname, a process number, a StatefulSet ordinal) - never a PID or a fresh UUID.

Omit `workerId` and the server mints a UUID instead, as before. Each restart then leaves the previous row holding a worker-cap slot until the stale-worker reaper clears it about two minutes later, which is enough for a crash-looping worker on a tight plan to quota itself out of registering.

An id owned by a different organization is rejected with `HTTP 409` (`ConflictError`).

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

`$key->lastUsedAt` is coarse: the API writes it at most once per key per five minutes, not once per request, so it can be up to five minutes behind. It answers "has this key been used lately", not "did that request just happen" - rotation tooling should allow a margin wider than the write interval before treating a key as unused.

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
echo "Plan: {$usage->plan}\n";
echo "Active jobs: {$usage->usage->activeJobs->current}\n";
```

### List Organizations

```php
$orgs = $client->organizations->list();
```

### Check Slug Availability

```php
$result = $client->organizations->checkSlug('my-company');
echo "Available: " . ($result['available'] ? 'yes' : 'no') . "\n";
echo "Suggestions: " . implode(', ', $result['suggestions'] ?? []) . "\n";
```

### Generate Slug

```php
$slug = $client->organizations->generateSlug('My Company Name');
echo "Slug: {$slug}\n";
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
$result = $client->auth->login('sp_live_...');
// $result->accessToken
// $result->refreshToken

// Use JWT for subsequent requests
$jwtClient = new SpooledClient(
    new ClientOptions(accessToken: $result->accessToken)
);
```

### Validate Token

```php
$result = $client->auth->validate($accessToken);
echo "Valid: " . ($result->valid ? 'yes' : 'no') . "\n";
```

### Refresh Token

```php
$result = $client->auth->refresh($refreshToken);
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
$dashboard = $client->dashboard->getStats();
// $dashboard->totalJobs
// $dashboard->queues
// $dashboard->system
// $dashboard->recentActivity
```

---

## Health & Metrics

### Health Check

```php
$health = $client->health->check();
echo "Status: {$health->status}\n";

// The public health endpoint may omit version, so this property is nullable.
if ($health->version !== null) {
    echo "Version: {$health->version}\n";
}
```

Do not use the public health response as deployment proof. For authenticated operational checks, read the backend version from dashboard data when the server supplies it:

```php
$dashboard = $client->dashboard->getStats();
$version = $dashboard->system['version'] ?? null;
```

### Readiness

```php
$ready = $client->health->isReady();
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
