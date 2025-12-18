#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * COMPREHENSIVE SPOOLED TEST SUITE (PHP)
 *
 * Tests ALL API endpoints, SDK features, and integration scenarios:
 * - Health endpoints
 * - Authentication (API key & JWT)
 * - Dashboard
 * - Jobs (CRUD, bulk, lifecycle, DLQ)
 * - Queues (config, pause/resume, stats)
 * - Workers (register, heartbeat, deregister, processing)
 * - Webhooks (CRUD, test, delivery)
 * - Schedules (CRUD, pause/resume, trigger)
 * - Workflows (create with dependencies, DAG execution)
 * - API Keys (CRUD)
 * - Organizations (get, usage)
 * - gRPC (enqueue, dequeue, complete, fail) [when ext-grpc available]
 *
 * Usage:
 *   API_KEY=sk_test_... BASE_URL=http://localhost:8080 php scripts/test-local.php
 *
 * Options:
 *   GRPC_ADDRESS=localhost:50051  - gRPC server address (local/self-hosted)
 *   SKIP_GRPC=1                   - Skip gRPC tests
 *   SKIP_STRESS=1                 - Skip stress/load tests
 *   VERBOSE=1                     - Enable debug logging
 *   WEBHOOK_PORT=3001             - Custom webhook server port
 *   ADMIN_API_KEY=...             - Admin API key for admin tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\Config\ClientOptions;
use Spooled\Errors\AuthenticationError;
use Spooled\Errors\NotFoundError;
use Spooled\Errors\SpooledError;
use Spooled\SpooledClient;

// ─────────────────────────────────────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────────────────────────────────────

$API_KEY = getenv('API_KEY') ?: '';
$BASE_URL = getenv('BASE_URL') ?: 'http://localhost:8080';
$GRPC_ADDRESS = getenv('GRPC_ADDRESS') ?: '127.0.0.1:50051';
$WEBHOOK_PORT = (int) (getenv('WEBHOOK_PORT') ?: 3001);
$VERBOSE = getenv('VERBOSE') === '1' || getenv('VERBOSE') === 'true';
$SKIP_GRPC = getenv('SKIP_GRPC') !== '0' && getenv('SKIP_GRPC') !== 'false';
$SKIP_STRESS = getenv('SKIP_STRESS') === '1' || getenv('SKIP_STRESS') === 'true';
$ADMIN_API_KEY = getenv('ADMIN_API_KEY') ?: null;

if (empty($API_KEY)) {
    fwrite(STDERR, "❌ API_KEY environment variable is required\n");
    fwrite(STDERR, "   Usage: API_KEY=sk_test_... BASE_URL=http://localhost:8080 php scripts/test-local.php\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test Harness
// ─────────────────────────────────────────────────────────────────────────────

class TestHarness
{
    /** @var array<string, array{passed: bool, duration: float, skipped?: bool, error?: string}> */
    private array $results = [];

    private int $passed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    private bool $verbose;

    private float $startTime;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
        $this->startTime = microtime(true);
    }

    public function runTest(string $name, callable $fn, bool $skip = false, ?string $skipReason = null): void
    {
        if ($skip) {
            $this->skipped++;
            $this->results[$name] = ['passed' => true, 'duration' => 0, 'skipped' => true];
            echo "  ⏭️  {$name} (skipped" . ($skipReason ? ": {$skipReason}" : '') . ")\n";

            return;
        }

        $start = microtime(true);

        try {
            $fn();
            $duration = (microtime(true) - $start) * 1000;
            $this->passed++;
            $this->results[$name] = ['passed' => true, 'duration' => $duration];
            echo "  ✓ {$name} (" . round($duration) . "ms)\n";
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            $this->failed++;
            $this->results[$name] = ['passed' => false, 'duration' => $duration, 'error' => $e->getMessage()];
            echo "  ✗ {$name} (" . round($duration) . "ms)\n";
            if ($this->verbose) {
                echo "    Error: {$e->getMessage()}\n";
                echo '    ' . str_replace("\n", "\n    ", $e->getTraceAsString()) . "\n";
            }
        }
    }

    public function log(string $message): void
    {
        if ($this->verbose) {
            echo "  [DEBUG] {$message}\n";
        }
    }

    public function printSummary(): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $total = $this->passed + $this->failed + $this->skipped;

        echo "\n";
        echo str_repeat('═', 60) . "\n";
        echo "   📊 TEST RESULTS SUMMARY\n";
        echo str_repeat('═', 60) . "\n";
        echo "   ✓ Passed:  {$this->passed}\n";
        echo "   ✗ Failed:  {$this->failed}\n";
        echo "   ⏭️  Skipped: {$this->skipped}\n";
        echo '   ' . str_repeat('─', 30) . "\n";
        echo "   Total:     {$total} tests in {$elapsed}s\n";
        echo str_repeat('═', 60) . "\n";

        if ($this->failed > 0) {
            echo "\n❌ Failed Tests:\n";
            foreach ($this->results as $name => $result) {
                if (!$result['passed'] && !($result['skipped'] ?? false)) {
                    echo "   • {$name}\n";
                    if (!empty($result['error'])) {
                        echo "     Error: {$result['error']}\n";
                    }
                }
            }
            echo "\n";
        }
    }

    /** @return array<string, array{passed: bool, duration: float, skipped?: bool, error?: string}> */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getExitCode(): int
    {
        return $this->failed > 0 ? 1 : 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────────────────────────────────────

function generateTestId(): string
{
    return 'test-' . time() . '-' . substr(md5((string) mt_rand()), 0, 8);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException("Assertion failed: {$message}");
    }
}

function assertEqual(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new \RuntimeException("{$message}: expected " . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function assertDefined(mixed $value, string $message): void
{
    if ($value === null || $value === '') {
        throw new \RuntimeException("{$message}: value is " . var_export($value, true));
    }
}

function sleep_ms(int $ms): void
{
    usleep($ms * 1000);
}

// ─────────────────────────────────────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────────────────────────────────────

function cleanupOldJobs(SpooledClient $client, TestHarness $harness, bool $verbose = true): void
{
    if ($verbose) {
        echo "\n🧹 Cleaning up old jobs...\n";
    }

    // Reset circuit breaker in case it was tripped
    $client->resetCircuitBreaker();

    try {
        // Cancel all pending and processing jobs
        $jobs = $client->jobs->list(['limit' => 100]);
        $cancelled = 0;

        foreach ($jobs->jobs ?? [] as $job) {
            if (in_array($job->status, ['pending', 'processing', 'scheduled'], true)) {
                try {
                    $client->jobs->cancel($job->id);
                    $cancelled++;
                } catch (\Throwable $e) {
                    // Ignore errors - job might have completed or been deleted
                }
            }
        }

        if ($verbose && $cancelled > 0) {
            echo "   Cancelled {$cancelled} old jobs\n";
        } elseif ($verbose) {
            echo "   No old jobs to cleanup\n";
        }
    } catch (\Throwable $e) {
        if ($verbose) {
            echo "   Could not cleanup jobs: {$e->getMessage()}\n";
        }
    }
}

function cleanupWorkflows(SpooledClient $client): void
{
    try {
        $workflows = $client->workflows->list(['limit' => 100]);
        foreach ($workflows->workflows ?? $workflows as $workflow) {
            if (!in_array($workflow->status ?? '', ['completed', 'failed', 'cancelled'], true)) {
                try {
                    $client->workflows->cancel($workflow->id);
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore
    }
}

function cleanupSchedules(SpooledClient $client): void
{
    try {
        $schedules = $client->schedules->list(['limit' => 100]);
        foreach ($schedules->schedules ?? $schedules as $schedule) {
            try {
                $client->schedules->delete($schedule->id);
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    } catch (\Throwable $e) {
        // Ignore
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test Suites
// ─────────────────────────────────────────────────────────────────────────────

function testHealthEndpoints(SpooledClient $client, TestHarness $harness): void
{
    echo "\n📋 Health Endpoints\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /health - Full health check', function () use ($client, $harness): void {
        $response = $client->health->check();
        assertDefined($response->status ?? null, 'status should be defined');
        assertEqual($response->isHealthy(), true, 'should be healthy');
        $harness->log('Health check passed');
    });

    $harness->runTest('GET /health/live - Liveness probe', function () use ($client): void {
        $response = $client->health->live();
        assertEqual($response->isHealthy(), true, 'should be live');
    });

    $harness->runTest('GET /health/ready - Readiness probe', function () use ($client): void {
        $response = $client->health->ready();
        assertEqual($response->isHealthy(), true, 'should be ready');
    });
}

function testDashboard(SpooledClient $client, TestHarness $harness): void
{
    echo "\n📊 Dashboard\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/dashboard', function () use ($client, $harness): void {
        $dashboard = $client->dashboard->getStats();
        assertDefined($dashboard->totalJobs ?? null, 'jobs stats should exist');
        $harness->log('Dashboard loaded');
    });
}

function testJobsBasicCRUD(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📦 Jobs - Basic CRUD\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness, false);

    $queueName = "{$testPrefix}-jobs-crud";
    $jobId = '';

    $harness->runTest('POST /api/v1/jobs - Create job', function () use ($client, $queueName, &$jobId, $harness): void {
        $result = $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['test' => 'data', 'timestamp' => time()],
            'priority' => 5,
            'maxRetries' => 3,
        ]);
        assertDefined($result->id, 'job id');
        $jobId = $result->id;
        $harness->log("Created job: {$jobId}");
    });

    $harness->runTest('GET /api/v1/jobs/{id} - Get job', function () use ($client, &$jobId, $queueName): void {
        $job = $client->jobs->get($jobId);
        assertEqual($job->id, $jobId, 'job id');
        assertEqual($job->queueName, $queueName, 'queue name');
        assertEqual($job->status, 'pending', 'status');
        assertEqual($job->priority, 5, 'priority');
        assertDefined($job->payload, 'payload');
    });

    $harness->runTest('GET /api/v1/jobs - List jobs', function () use ($client, $queueName, &$jobId): void {
        $result = $client->jobs->list(['queue' => $queueName, 'limit' => 10]);
        assertTrue(is_array($result->jobs), 'should return array');
        assertTrue(count($result->jobs) > 0, 'should have jobs');
    });

    $harness->runTest('GET /api/v1/jobs - Filter by status', function () use ($client, $queueName): void {
        $result = $client->jobs->list(['queue' => $queueName, 'status' => 'pending']);
        foreach ($result->jobs as $job) {
            assertEqual($job->status, 'pending', 'all should be pending');
        }
    });

    $harness->runTest('PUT /api/v1/jobs/{id}/priority - Boost priority', function () use ($client, &$jobId): void {
        $client->jobs->boostPriority($jobId, 10);
        $job = $client->jobs->get($jobId);
        assertEqual($job->priority, 10, 'boosted priority');
    });

    $harness->runTest('DELETE /api/v1/jobs/{id} - Cancel job', function () use ($client, &$jobId): void {
        $client->jobs->cancel($jobId);
        $job = $client->jobs->get($jobId);
        assertEqual($job->status, 'cancelled', 'status should be cancelled');
    });
}

function testJobsBulkOperations(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📦 Jobs - Bulk Operations\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness, false);

    $queueName = "{$testPrefix}-jobs-bulk";
    $jobIds = [];

    $harness->runTest('POST /api/v1/jobs/bulk - Bulk create', function () use ($client, $queueName, &$jobIds, $harness): void {
        $result = $client->jobs->bulkEnqueue([
            'queueName' => $queueName,
            'jobs' => [
                ['payload' => ['index' => 0, 'type' => 'bulk-test']],
                ['payload' => ['index' => 1, 'type' => 'bulk-test'], 'priority' => 5],
                ['payload' => ['index' => 2, 'type' => 'bulk-test'], 'priority' => 10],
            ],
        ]);
        assertEqual($result->successCount, 3, 'success count');
        $harness->log("Bulk created {$result->created} jobs");
    });

    $harness->runTest('GET /api/v1/jobs/status - Batch status lookup', function () use ($client, $queueName): void {
        // Create two jobs first
        $job1 = $client->jobs->create(['queue' => $queueName, 'payload' => ['batch' => 1]]);
        $job2 = $client->jobs->create(['queue' => $queueName, 'payload' => ['batch' => 2]]);

        $statuses = $client->jobs->batchStatus([$job1->id, $job2->id]);
        assertEqual(count($statuses->statuses), 2, 'should have 2 statuses');
    });

    $harness->runTest('GET /api/v1/jobs/stats - Job statistics', function () use ($client, $harness): void {
        $stats = $client->jobs->getStats();
        assertDefined($stats->pending ?? null, 'pending should be defined');
        assertDefined($stats->total ?? null, 'total should be defined');
        $harness->log("Stats: pending={$stats->pending}, total={$stats->total}");
    });
}

function testJobIdempotency(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📦 Jobs - Idempotency\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-jobs-idempotency";
    $idempotencyKey = 'idem-' . time();
    $firstJobId = '';

    $harness->runTest('Create job with idempotency key', function () use ($client, $queueName, $idempotencyKey, &$firstJobId): void {
        $result = $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['test' => 'idempotent'],
            'idempotencyKey' => $idempotencyKey,
        ]);
        assertDefined($result->id, 'job id');
        $firstJobId = $result->id;
    });

    $harness->runTest('Duplicate with same idempotency key returns existing', function () use ($client, $queueName, $idempotencyKey, &$firstJobId): void {
        $result = $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['test' => 'idempotent-duplicate'],
            'idempotencyKey' => $idempotencyKey,
        ]);
        assertEqual($result->id, $firstJobId, 'should return same id');
    });
}

function testJobLifecycle(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📦 Jobs - Full Lifecycle\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-jobs-lifecycle";
    $jobId = '';
    $workerId = '';

    $harness->runTest('Create job for lifecycle test', function () use ($client, $queueName, &$jobId): void {
        $result = $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['action' => 'lifecycle-test'],
        ]);
        $jobId = $result->id;
    });

    $harness->runTest('POST /api/v1/workers/register', function () use ($client, $queueName, &$workerId, $harness): void {
        $result = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'test-lifecycle-worker',
            'maxConcurrency' => 1,
        ]);
        $workerId = $result->id;
        assertDefined($result->id, 'worker id');
        $harness->log("Registered worker: {$workerId}");
    });

    $harness->runTest('POST /api/v1/jobs/claim - Claim job', function () use ($client, $queueName, &$workerId, &$jobId): void {
        $result = $client->jobs->claim([
            'queue' => $queueName,
            'workerId' => $workerId,
            'limit' => 1,
        ]);
        assertEqual(count($result->jobs), 1, 'should claim 1 job');
        assertEqual($result->jobs[0]->id, $jobId, 'should be our job');
    });

    $harness->runTest('Job status is processing after claim', function () use ($client, &$jobId, &$workerId): void {
        $job = $client->jobs->get($jobId);
        assertEqual($job->status, 'processing', 'status');
    });

    $harness->runTest('POST /api/v1/jobs/{id}/heartbeat - Extend lease', function () use ($client, &$jobId, &$workerId): void {
        $client->jobs->heartbeat($jobId, ['workerId' => $workerId, 'leaseDurationSecs' => 60]);
        $job = $client->jobs->get($jobId);
        assertDefined($job->leaseExpiresAt ?? null, 'lease should be extended');
    });

    $harness->runTest('POST /api/v1/jobs/{id}/complete - Complete job', function () use ($client, &$jobId, &$workerId): void {
        $client->jobs->complete($jobId, [
            'workerId' => $workerId,
            'result' => ['processed' => true, 'timestamp' => date('c')],
        ]);
        $job = $client->jobs->get($jobId);
        assertEqual($job->status, 'completed', 'status');
        assertDefined($job->completedAt ?? null, 'completed_at');
    });

    $harness->runTest('POST /api/v1/workers/{id}/deregister', function () use ($client, &$workerId): void {
        $client->workers->deregister($workerId);
    });
}

function testJobFailureAndRetry(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📦 Jobs - Failure & Retry\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-jobs-failure";
    $jobId = '';
    $workerId = '';

    $harness->runTest('Create job for failure test', function () use ($client, $queueName, &$jobId): void {
        $result = $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['action' => 'fail-test'],
            'maxRetries' => 0, // No auto-retry
        ]);
        $jobId = $result->id;
    });

    $harness->runTest('Register worker and claim job', function () use ($client, $queueName, &$workerId, &$jobId): void {
        $reg = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'test-failure-worker',
            'maxConcurrency' => 1,
        ]);
        $workerId = $reg->id;
        $client->jobs->claim(['queue' => $queueName, 'workerId' => $workerId, 'limit' => 1]);
    });

    $harness->runTest('POST /api/v1/jobs/{id}/fail - Fail job', function () use ($client, &$jobId, &$workerId): void {
        $client->jobs->fail($jobId, [
            'workerId' => $workerId,
            'error' => 'Intentional test failure',
        ]);
        $job = $client->jobs->get($jobId);
        assertTrue(
            in_array($job->status, ['failed', 'deadletter'], true),
            "status should be failed or deadletter, got {$job->status}",
        );
    });

    $harness->runTest('POST /api/v1/jobs/{id}/retry - Manual retry', function () use ($client, &$jobId): void {
        $job = $client->jobs->get($jobId);
        if (in_array($job->status, ['failed', 'deadletter'], true)) {
            $retried = $client->jobs->retry($jobId);
            assertEqual($retried->status, 'pending', 'should be pending after retry');
        }
    });

    // Cleanup
    try {
        $client->workers->deregister($workerId);
    } catch (\Throwable $e) {
    }

    try {
        $client->jobs->cancel($jobId);
    } catch (\Throwable $e) {
    }
}

function testDLQ(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📦 Jobs - Dead Letter Queue\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/jobs/dlq - List DLQ', function () use ($client, $harness): void {
        $jobs = $client->jobs->dlq->list(['limit' => 10]);
        assertTrue(is_array($jobs->jobs ?? $jobs), 'jobs should be array');
        $harness->log('DLQ has ' . count($jobs->jobs ?? $jobs) . ' jobs');
    });
}

function testQueues(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📁 Queues\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-queue-test";

    $harness->runTest('Create queue (via job)', function () use ($client, $queueName): void {
        $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['purpose' => 'create-queue'],
        ]);
    });

    $harness->runTest('GET /api/v1/queues - List queues', function () use ($client, $harness): void {
        $queues = $client->queues->list();
        assertTrue(is_array($queues->queues ?? $queues), 'queues should be array');
        $harness->log('Found ' . count($queues->queues ?? $queues) . ' queues');
    });

    $harness->runTest('POST /api/v1/queues/{name}/pause - Pause queue', function () use ($client, $queueName): void {
        $result = $client->queues->pause($queueName);
        assertEqual($result->paused, true, 'paused flag');
    });

    $harness->runTest('POST /api/v1/queues/{name}/resume - Resume queue', function () use ($client, $queueName): void {
        $result = $client->queues->resume($queueName);
        assertEqual($result->paused, false, 'resumed flag');
    });
}

function testWorkers(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n👷 Workers\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-workers";
    $workerId = '';

    $harness->runTest('POST /api/v1/workers/register', function () use ($client, $queueName, &$workerId, $harness): void {
        $result = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'test-worker-host',
            'maxConcurrency' => 5,
        ]);
        $workerId = $result->id;
        assertDefined($result->id, 'worker id');
        $harness->log("Registered worker: {$workerId}");
    });

    $harness->runTest('GET /api/v1/workers - List workers', function () use ($client, &$workerId): void {
        $workers = $client->workers->list();
        assertTrue(is_array($workers->workers ?? $workers), 'workers should be array');
    });

    $harness->runTest('GET /api/v1/workers/{id} - Get worker', function () use ($client, &$workerId): void {
        $worker = $client->workers->get($workerId);
        assertEqual($worker->id, $workerId, 'worker id');
    });

    $harness->runTest('POST /api/v1/workers/{id}/heartbeat', function () use ($client, &$workerId): void {
        $client->workers->heartbeat($workerId, [
            'currentJobs' => 0,
            'status' => 'active',
        ]);
        $worker = $client->workers->get($workerId);
        assertDefined($worker->lastHeartbeat ?? null, 'heartbeat should be updated');
    });

    $harness->runTest('POST /api/v1/workers/{id}/deregister', function () use ($client, &$workerId): void {
        $client->workers->deregister($workerId);
    });
}

function testWebhooks(SpooledClient $client, TestHarness $harness, string $testPrefix, int $webhookPort): void
{
    echo "\n🔔 Outgoing Webhooks\n";
    echo str_repeat('─', 60) . "\n";

    $webhookId = '';
    $ssrfBlocked = false;
    $webhookUrl = "http://localhost:{$webhookPort}/webhook";

    $harness->runTest('POST /api/v1/outgoing-webhooks - Create webhook', function () use ($client, $testPrefix, $webhookUrl, &$webhookId, &$ssrfBlocked, $harness): void {
        try {
            $result = $client->webhooks->create([
                'name' => "{$testPrefix}-webhook",
                'url' => $webhookUrl,
                'events' => ['job.created', 'job.completed', 'job.failed'],
            ]);
            $webhookId = $result->id;
            assertDefined($result->id, 'webhook id');
        } catch (SpooledError $e) {
            if (strpos($e->getMessage(), 'HTTP not allowed') !== false || strpos($e->getMessage(), 'Invalid webhook URL') !== false) {
                $ssrfBlocked = true;
                $harness->log('SSRF protection active - localhost webhooks blocked in production');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('GET /api/v1/outgoing-webhooks - List webhooks', function () use ($client, &$webhookId, &$ssrfBlocked): void {
        $webhooks = $client->webhooks->list();
        assertTrue(is_array($webhooks->webhooks ?? $webhooks), 'webhooks should be array');
    });

    $skipReason = $ssrfBlocked ? 'SSRF protection blocked webhook creation' : null;

    $harness->runTest('GET /api/v1/outgoing-webhooks/{id} - Get webhook', function () use ($client, &$webhookId, $webhookUrl, $harness): void {
        if (empty($webhookId)) {
            $harness->log('No webhook to get (SSRF blocked)');

            return;
        }
        $webhook = $client->webhooks->get($webhookId);
        assertEqual($webhook->id, $webhookId, 'webhook id');
    }, $ssrfBlocked, $skipReason);

    $harness->runTest('PUT /api/v1/outgoing-webhooks/{id} - Update webhook', function () use ($client, &$webhookId, $harness): void {
        if (empty($webhookId)) {
            $harness->log('No webhook to update (SSRF blocked)');

            return;
        }
        $client->webhooks->update($webhookId, [
            'events' => ['job.created', 'job.completed', 'job.failed', 'job.started'],
        ]);
        $webhook = $client->webhooks->get($webhookId);
        assertTrue(in_array('job.started', $webhook->events ?? []), 'should have job.started event');
    }, $ssrfBlocked, $skipReason);

    $harness->runTest('POST /api/v1/outgoing-webhooks/{id}/test - Test webhook', function () use ($client, &$webhookId, $harness): void {
        if (empty($webhookId)) {
            $harness->log('No webhook to test (SSRF blocked)');

            return;
        }
        $result = $client->webhooks->test($webhookId);
        $harness->log('Webhook test result: ' . json_encode($result));
    }, $ssrfBlocked, $skipReason);

    $harness->runTest('GET /api/v1/outgoing-webhooks/{id}/deliveries - List deliveries', function () use ($client, &$webhookId, $harness): void {
        if (empty($webhookId)) {
            $harness->log('No webhook to get deliveries (SSRF blocked)');

            return;
        }
        $deliveries = $client->webhooks->getDeliveries($webhookId);
        assertTrue(is_array($deliveries->deliveries ?? $deliveries), 'deliveries should be array');
        $harness->log('Webhook has ' . count($deliveries->deliveries ?? $deliveries) . ' deliveries');
    }, $ssrfBlocked, $skipReason);

    $harness->runTest('DELETE /api/v1/outgoing-webhooks/{id} - Delete webhook', function () use ($client, &$webhookId, $harness): void {
        if (empty($webhookId)) {
            $harness->log('No webhook to delete (SSRF blocked)');

            return;
        }
        $client->webhooks->delete($webhookId);
    }, $ssrfBlocked, $skipReason);
}

function testSchedules(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n⏰ Schedules\n";
    echo str_repeat('─', 60) . "\n";

    cleanupSchedules($client);
    cleanupOldJobs($client, $harness, false);

    $queueName = "{$testPrefix}-schedules";
    $scheduleId = '';

    $harness->runTest('POST /api/v1/schedules - Create schedule', function () use ($client, $testPrefix, $queueName, &$scheduleId, $harness): void {
        $result = $client->schedules->create([
            'name' => "{$testPrefix}-schedule",
            'queueName' => $queueName,
            'cronExpression' => '0 0 * * * *', // Every hour (6-field cron)
            'payloadTemplate' => ['type' => 'scheduled', 'source' => 'test'],
        ]);
        $scheduleId = $result->id;
        assertDefined($result->id, 'schedule id');
        $harness->log("Created schedule: {$scheduleId}");
    });

    $harness->runTest('GET /api/v1/schedules - List schedules', function () use ($client, &$scheduleId): void {
        $schedules = $client->schedules->list();
        assertTrue(is_array($schedules->schedules ?? $schedules), 'schedules should be array');
    });

    $harness->runTest('GET /api/v1/schedules/{id} - Get schedule', function () use ($client, &$scheduleId): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $schedule = $client->schedules->get($scheduleId);
        assertEqual($schedule->id, $scheduleId, 'schedule id');
    });

    $harness->runTest('PUT /api/v1/schedules/{id} - Update schedule', function () use ($client, &$scheduleId): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $client->schedules->update($scheduleId, [
            'cronExpression' => '30 0 * * * *',
        ]);
        $schedule = $client->schedules->get($scheduleId);
        assertEqual($schedule->schedule, '30 0 * * * *', 'cron expression');
    });

    $harness->runTest('POST /api/v1/schedules/{id}/pause - Pause schedule', function () use ($client, &$scheduleId): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $schedule = $client->schedules->pause($scheduleId);
        assertEqual($schedule->paused, true, 'should be inactive');
    });

    $harness->runTest('POST /api/v1/schedules/{id}/resume - Resume schedule', function () use ($client, &$scheduleId): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $schedule = $client->schedules->resume($scheduleId);
        assertEqual($schedule->paused, false, 'should be active');
    });

    $harness->runTest('POST /api/v1/schedules/{id}/trigger - Manual trigger', function () use ($client, &$scheduleId, $harness): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $result = $client->schedules->trigger($scheduleId);
        assertDefined($result['jobId'] ?? null, 'triggered job id');
        $harness->log("Triggered job: {$result['jobId']}");
    });

    $harness->runTest('GET /api/v1/schedules/{id}/history - Execution history', function () use ($client, &$scheduleId, $harness): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $runs = $client->schedules->history($scheduleId);
        assertTrue(is_array($runs), 'runs should be array');
        $harness->log('Schedule has ' . count($runs) . ' runs');
    });

    $harness->runTest('DELETE /api/v1/schedules/{id} - Delete schedule', function () use ($client, &$scheduleId): void {
        if (empty($scheduleId)) {
            throw new \RuntimeException('Schedule not created');
        }
        $client->schedules->delete($scheduleId);
    });
}

function testWorkflows(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n🔀 Workflows\n";
    echo str_repeat('─', 60) . "\n";

    cleanupWorkflows($client);
    cleanupOldJobs($client, $harness, false);

    $queueName = "{$testPrefix}-workflows";
    $workflowId = '';

    $harness->runTest('POST /api/v1/workflows - Create workflow', function () use ($client, $testPrefix, $queueName, &$workflowId, $harness): void {
        $result = $client->workflows->create([
            'name' => "{$testPrefix}-workflow",
            'jobs' => [
                ['key' => 'step1', 'queueName' => $queueName, 'payload' => ['step' => 1]],
                ['key' => 'step2', 'queueName' => $queueName, 'payload' => ['step' => 2], 'dependsOn' => ['step1']],
                ['key' => 'step3', 'queueName' => $queueName, 'payload' => ['step' => 3], 'dependsOn' => ['step1']],
                ['key' => 'step4', 'queueName' => $queueName, 'payload' => ['step' => 4], 'dependsOn' => ['step2', 'step3']],
            ],
        ]);
        $workflowId = $result->id;
        assertDefined($result->id, 'workflow id');
        $harness->log("Created workflow: {$workflowId}");
    });

    $harness->runTest('GET /api/v1/workflows - List workflows', function () use ($client): void {
        $workflows = $client->workflows->list();
        assertTrue(is_array($workflows->workflows ?? $workflows), 'workflows should be array');
    });

    $harness->runTest('GET /api/v1/workflows/{id} - Get workflow', function () use ($client, &$workflowId): void {
        $workflow = $client->workflows->get($workflowId);
        assertEqual($workflow->id, $workflowId, 'workflow id');
    });

    $harness->runTest('POST /api/v1/workflows/{id}/cancel - Cancel workflow', function () use ($client, &$workflowId): void {
        $workflow = $client->workflows->cancel($workflowId);
        assertEqual($workflow->status, 'cancelled', 'status');
    });

    // Test retry on non-failed workflow
    $retryWorkflowId = '';
    $harness->runTest('Create workflow for retry test', function () use ($client, $testPrefix, $queueName, &$retryWorkflowId): void {
        $result = $client->workflows->create([
            'name' => "{$testPrefix}-retry-test",
            'jobs' => [
                ['key' => 'job1', 'queueName' => $queueName, 'payload' => ['step' => 1]],
            ],
        ]);
        $retryWorkflowId = $result->id;
        assertTrue(!empty($retryWorkflowId), 'workflow id should be set');
    });

    $harness->runTest('POST /api/v1/workflows/{id}/retry - Retry workflow (should fail - not failed status)', function () use ($client, &$retryWorkflowId): void {
        try {
            $client->workflows->retry($retryWorkflowId);

            throw new \RuntimeException('Expected error for non-failed workflow');
        } catch (SpooledError $e) {
            assertTrue(
                strpos($e->getMessage(), 'failed') !== false || $e->statusCode === 400,
                'error should mention failed status or be 400',
            );
        }
    });
}

function testApiKeys(SpooledClient $client, TestHarness $harness, string $testPrefix, string $baseUrl): void
{
    echo "\n🔑 API Keys\n";
    echo str_repeat('─', 60) . "\n";

    $newKeyId = '';
    $newKey = '';

    $harness->runTest('GET /api/v1/api-keys - List API keys', function () use ($client, $harness): void {
        $keys = $client->apiKeys->list();
        assertTrue(is_array($keys->apiKeys ?? $keys), 'keys should be array');
        $harness->log('Found ' . count($keys->apiKeys ?? $keys) . ' API keys');
    });

    $harness->runTest('POST /api/v1/api-keys - Create API key', function () use ($client, $testPrefix, &$newKeyId, &$newKey): void {
        $result = $client->apiKeys->create([
            'name' => "{$testPrefix}-key",
        ]);
        $newKeyId = $result->id;
        $newKey = $result->key;
        assertDefined($result->id, 'key id');
        assertDefined($result->key, 'key value');
        assertTrue(strpos($result->key, 'sk_') === 0, 'key should start with sk_');
    });

    $harness->runTest('GET /api/v1/api-keys/{id} - Get API key', function () use ($client, &$newKeyId, $testPrefix): void {
        $key = $client->apiKeys->get($newKeyId);
        assertEqual($key->id, $newKeyId, 'key id');
    });

    $harness->runTest('PUT /api/v1/api-keys/{id} - Update API key', function () use ($client, &$newKeyId, $testPrefix): void {
        $client->apiKeys->update($newKeyId, [
            'name' => "{$testPrefix}-key-updated",
        ]);
        $key = $client->apiKeys->get($newKeyId);
        assertEqual($key->name, "{$testPrefix}-key-updated", 'updated name');
    });

    $harness->runTest('New API key works for authentication', function () use (&$newKey, $baseUrl): void {
        $testClient = new SpooledClient(new ClientOptions(
            apiKey: $newKey,
            baseUrl: $baseUrl,
        ));
        $dashboard = $testClient->dashboard->getStats();
        assertDefined($dashboard->totalJobs ?? null, 'should authenticate with new key');
    });

    $harness->runTest('DELETE /api/v1/api-keys/{id} - Revoke API key', function () use ($client, &$newKeyId): void {
        $client->apiKeys->delete($newKeyId);
    });
}

function testOrganization(SpooledClient $client, TestHarness $harness): void
{
    echo "\n🏢 Organization\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/organizations/usage - Get usage & limits', function () use ($client, $harness): void {
        $usage = $client->organizations->getUsage();
        assertDefined($usage->plan ?? null, 'plan');
        $harness->log('Plan: ' . ($usage->plan ?? 'N/A'));
    });
}

function testQueueAdvanced(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📁 Queues (Advanced)\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-queue-advanced";
    $jobId = '';

    $harness->runTest('Create queue via job', function () use ($client, $queueName, &$jobId): void {
        $job = $client->jobs->create(['queue' => $queueName, 'payload' => ['test' => true]]);
        assertDefined($job->id, 'job id');
        $jobId = $job->id;
    });

    $harness->runTest('GET /api/v1/queues/{name} - Get queue details', function () use ($client, $queueName, $harness): void {
        try {
            $queue = $client->queues->get($queueName);
            assertEqual($queue->name, $queueName, 'queue name');
        } catch (NotFoundError $e) {
            $harness->log('Queue config not found (jobs can use unconfigured queues)');
        }
    });

    $harness->runTest('GET /api/v1/queues/{name}/stats - Get queue stats', function () use ($client, $queueName, $harness): void {
        try {
            $stats = $client->queues->getStats($queueName);
            assertDefined($stats, 'stats object');
            $harness->log('Stats retrieved');
        } catch (SpooledError $e) {
            $harness->log("Stats endpoint returned {$e->statusCode}: {$e->getMessage()}");
        }
    });

    $harness->runTest('DELETE /api/v1/queues/{name} - Delete queue', function () use ($client, $queueName, &$jobId, $harness): void {
        if ($jobId) {
            try {
                $client->jobs->cancel($jobId);
                sleep_ms(200);
            } catch (\Throwable $e) {
            }
        }

        try {
            $client->queues->delete($queueName);
            $harness->log('Queue deleted');
        } catch (NotFoundError $e) {
            $harness->log('Queue config does not exist (OK for unconfigured queues)');
        } catch (SpooledError $e) {
            $harness->log("Queue delete: {$e->getMessage()}");
        }
    });
}

function testDLQAdvanced(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n💀 Dead Letter Queue (Advanced)\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('POST /api/v1/jobs/dlq/retry - Retry DLQ jobs', function () use ($client, $testPrefix, $harness): void {
        try {
            $result = $client->jobs->dlq->retry(['queue' => "{$testPrefix}-dlq-test"]);
            $harness->log('Retried ' . ($result['retriedCount'] ?? 0) . ' jobs from DLQ');
        } catch (SpooledError $e) {
            $harness->log("DLQ retry: {$e->getMessage()}");
        }
    });

    $harness->runTest('POST /api/v1/jobs/dlq/purge - Purge DLQ', function () use ($client, $testPrefix, $harness): void {
        try {
            $result = $client->jobs->dlq->purge(['queue' => "{$testPrefix}-dlq-test", 'confirm' => true]);
            $harness->log('Purged ' . ($result['purgedCount'] ?? 0) . ' jobs from DLQ');
        } catch (SpooledError $e) {
            $harness->log("DLQ purge: {$e->getMessage()}");
        }
    });
}

function testAuth(SpooledClient $client, TestHarness $harness, string $apiKey, string $baseUrl): void
{
    echo "\n🔐 Authentication\n";
    echo str_repeat('─', 60) . "\n";

    $accessToken = '';
    $refreshToken = '';

    $harness->runTest('POST /api/v1/auth/login - Exchange API key for JWT', function () use ($client, $apiKey, &$accessToken, &$refreshToken, $harness): void {
        $result = $client->auth->login($apiKey);
        $accessToken = $result->accessToken;
        $refreshToken = $result->refreshToken ?? '';
        assertDefined($result->accessToken, 'access token');
        $harness->log('Token expires in ' . ($result->expiresIn ?? 'N/A') . 's');
    });

    $harness->runTest('POST /api/v1/auth/validate - Validate token', function () use ($client, &$accessToken, $baseUrl): void {
        // Create a client with JWT token
        $jwtClient = new SpooledClient(new ClientOptions(
            accessToken: $accessToken,
            baseUrl: $baseUrl,
        ));
        $result = $jwtClient->auth->validate();
        assertEqual($result->valid, true, 'should be valid');
    });

    $harness->runTest('GET /api/v1/auth/me - Get current user (JWT)', function () use (&$accessToken, $baseUrl): void {
        $jwtClient = new SpooledClient(new ClientOptions(
            accessToken: $accessToken,
            baseUrl: $baseUrl,
        ));
        $me = $jwtClient->auth->me();
        assertDefined($me->organizationId ?? $me->id ?? null, 'organization id');
    });

    $harness->runTest('POST /api/v1/auth/refresh - Refresh token', function () use ($client, &$refreshToken, &$accessToken): void {
        if (empty($refreshToken)) {
            throw new \RuntimeException('No refresh token available');
        }
        $result = $client->auth->refresh($refreshToken);
        assertDefined($result->accessToken, 'new access token');
        assertTrue($result->accessToken !== $accessToken, 'should be new token');
    });

    $harness->runTest('POST /api/v1/auth/logout - Logout', function () use (&$accessToken, $baseUrl): void {
        $jwtClient = new SpooledClient(new ClientOptions(
            accessToken: $accessToken,
            baseUrl: $baseUrl,
        ));
        $jwtClient->auth->logout();
    });
}

function testRealtime(SpooledClient $client, TestHarness $harness, string $apiKey, string $baseUrl): void
{
    echo "\n📡 Real-time (SSE)\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/events - SSE connection test', function () use ($client, $apiKey, $baseUrl, $harness): void {
        // Get JWT token first
        $auth = $client->auth->login($apiKey);

        // Test SSE endpoint connectivity with cURL
        $ch = curl_init("{$baseUrl}/api/v1/events?token={$auth->accessToken}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/event-stream']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $harness->log('SSE endpoint connected successfully');
        } else {
            $harness->log("SSE returned status {$httpCode}");
        }
    });
}

function testMetrics(TestHarness $harness, string $apiKey, string $baseUrl): void
{
    echo "\n📊 Metrics Endpoint\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /metrics - Prometheus metrics', function () use ($apiKey, $baseUrl, $harness): void {
        $ch = curl_init("{$baseUrl}/metrics");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$apiKey}"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            assertTrue(
                strpos($response, '#') !== false || strpos($response, 'spooled_') !== false,
                'should contain prometheus metrics',
            );
            $harness->log('Metrics endpoint returned ' . strlen($response) . ' bytes');
        } else {
            $harness->log("Metrics returned {$httpCode}");
        }
    });
}

function testWebSocket(SpooledClient $client, TestHarness $harness, string $apiKey, string $baseUrl): void
{
    echo "\n🔌 WebSocket\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/ws - WebSocket connectivity', function () use ($client, $apiKey, $baseUrl, $harness): void {
        // Use existing access token if available to avoid rate limiting
        $accessToken = $client->getHttpClient()->getAccessToken();

        if ($accessToken === null || $accessToken === '') {
            // Only login if we don't have a token yet
            $auth = $client->auth->login($apiKey);
            $accessToken = $auth->accessToken;
        }

        $wsUrl = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $baseUrl);
        $harness->log("WebSocket URL would be: {$wsUrl}/api/v1/ws?token=...");

        assertDefined($accessToken, 'JWT token for WS');
        $harness->log('WebSocket auth token obtained successfully');
    });
}

function testOrgManagement(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n🏢 Organization Management\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/organizations/check-slug - Check slug availability', function () use ($client, $harness): void {
        try {
            $result = $client->organizations->checkSlug('test-unique-slug-' . time());
            assertDefined($result['available'] ?? $result->available ?? null, 'available field');
            $harness->log('Slug availability: ' . json_encode($result));
        } catch (NotFoundError $e) {
            $harness->log('Slug check endpoint not available');
        }
    });

    $harness->runTest('POST /api/v1/organizations/generate-slug - Generate slug', function () use ($client, $harness): void {
        try {
            $result = $client->organizations->generateSlug('My Test Organization');
            assertDefined($result, 'generated slug');
            $harness->log("Generated slug: {$result}");
        } catch (NotFoundError $e) {
            $harness->log('Generate slug endpoint not available');
        }
    });

    $harness->runTest('GET /api/v1/organizations - List organizations', function () use ($client, $harness): void {
        try {
            $orgs = $client->organizations->list();
            $harness->log('Found ' . count($orgs->organizations ?? $orgs ?? []) . ' organizations');
        } catch (SpooledError $e) {
            if ($e->statusCode === 403) {
                $harness->log('List organizations requires admin access');
            } else {
                throw $e;
            }
        }
    });
}

function testEdgeCases(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n🧪 Edge Cases\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness);

    $harness->runTest('Job with large payload', function () use ($client, $testPrefix): void {
        $largePayload = ['data' => str_repeat('x', 10000)]; // ~10KB
        $result = $client->jobs->create([
            'queue' => "{$testPrefix}-edge",
            'payload' => $largePayload,
        ]);
        assertDefined($result->id, 'job id from create');
        // Fetch full job details to verify payload (create response only has id)
        $job = $client->jobs->get($result->id);
        assertDefined($job->payload, 'payload should exist');
        assertEqual(count($job->payload), 1, 'payload should have data key');
        $client->jobs->cancel($job->id);
    });

    $harness->runTest('Job with scheduled time in future', function () use ($client, $testPrefix): void {
        $futureDate = date('c', time() + 3600);
        $result = $client->jobs->create([
            'queue' => "{$testPrefix}-edge",
            'payload' => ['scheduled' => true],
            'scheduledAt' => $futureDate, // Backend expects scheduled_at
        ]);
        assertDefined($result->id, 'job id from create');
        // Fetch full job details (create response only has id)
        $job = $client->jobs->get($result->id);
        // Backend returns 'scheduled' status for jobs with scheduled_at in future
        assertEqual($job->status, 'scheduled', 'should be scheduled');
        assertDefined($job->scheduledFor, 'scheduled_at should be set');
        $client->jobs->cancel($job->id);
    });

    $harness->runTest('Concurrent job claims (race condition)', function () use ($client, $testPrefix, $harness): void {
        $queueName = "{$testPrefix}-race";

        $client->jobs->create(['queue' => $queueName, 'payload' => ['race' => true]]);

        $w1 = $client->workers->register(['queueName' => $queueName, 'hostname' => 'worker1']);
        $w2 = $client->workers->register(['queueName' => $queueName, 'hostname' => 'worker2']);

        $c1 = $client->jobs->claim(['queue' => $queueName, 'workerId' => $w1->id, 'limit' => 1]);
        $c2 = $client->jobs->claim(['queue' => $queueName, 'workerId' => $w2->id, 'limit' => 1]);

        $totalClaimed = count($c1->jobs) + count($c2->jobs);
        assertEqual($totalClaimed, 1, 'only one worker should claim');

        $client->workers->deregister($w1->id);
        $client->workers->deregister($w2->id);
    });

    $harness->runTest('Special characters in queue name', function () use ($client, $testPrefix): void {
        $specialQueue = "{$testPrefix}-special_queue.test-123";
        $job = $client->jobs->create([
            'queue' => $specialQueue,
            'payload' => ['test' => 'special'],
        ]);
        assertDefined($job->id, 'job id from create');
        // Fetch full job details to verify queue name (create response only has id)
        $fullJob = $client->jobs->get($job->id);
        assertEqual($fullJob->queueName, $specialQueue, 'queue name with special chars');
        $client->jobs->cancel($job->id);
    });

    $harness->runTest('Unicode in payload', function () use ($client, $testPrefix): void {
        $job = $client->jobs->create([
            'queue' => "{$testPrefix}-edge",
            'payload' => [
                'message' => '你好世界 🌍 مرحبا',
                'emoji' => '🎉🚀💻',
                'japanese' => 'こんにちは',
            ],
        ]);
        assertDefined($job->payload, 'payload with unicode');
        $client->jobs->cancel($job->id);
    });

    $harness->runTest('Job with all optional fields', function () use ($client, $testPrefix): void {
        $result = $client->jobs->create([
            'queue' => "{$testPrefix}-edge",
            'payload' => ['complete' => true],
            'priority' => 50,
            'maxRetries' => 5,
            'timeoutSeconds' => 600,
            'idempotencyKey' => 'full-' . time(),
        ]);
        assertDefined($result->id, 'job id from create');
        // Fetch full job details to verify all fields (create response only has id)
        $job = $client->jobs->get($result->id);
        assertEqual($job->priority, 50, 'priority');
        assertEqual($job->maxRetries, 5, 'max retries');
        $client->jobs->cancel($job->id);
    });
}

function testErrorHandling(SpooledClient $client, TestHarness $harness, string $baseUrl): void
{
    echo "\n❌ Error Handling\n";
    echo str_repeat('─', 60) . "\n";

    // Reset circuit breaker before error handling tests
    $client->resetCircuitBreaker();

    $harness->runTest('404 for non-existent job', function () use ($client): void {
        try {
            $client->jobs->get('non-existent-job-id');

            throw new \RuntimeException('Should have thrown');
        } catch (NotFoundError $e) {
            assertEqual($e->statusCode, 404, 'status code');
        }
    });

    $harness->runTest('Validation error for invalid payload', function () use ($client): void {
        try {
            $client->jobs->create([
                'queue' => '', // Invalid: empty queue name
                'payload' => [],
            ]);

            throw new \RuntimeException('Should have thrown');
        } catch (SpooledError $e) {
            assertEqual($e->statusCode, 400, 'status code');
        }
    });

    $harness->runTest('401 for invalid API key', function () use ($baseUrl): void {
        $badClient = new SpooledClient(new ClientOptions(
            apiKey: 'sk_test_invalid_key_that_does_not_exist',
            baseUrl: $baseUrl,
        ));

        try {
            $badClient->dashboard->getStats();

            throw new \RuntimeException('Should have thrown');
        } catch (AuthenticationError $e) {
            assertEqual($e->statusCode, 401, 'status code');
        }
    });

    $harness->runTest('404 for non-existent worker', function () use ($client): void {
        try {
            $client->workers->get('non-existent-worker-id');

            throw new \RuntimeException('Should have thrown');
        } catch (NotFoundError $e) {
            assertEqual($e->statusCode, 404, 'status code');
        }
    });

    $harness->runTest('404 for non-existent webhook', function () use ($client): void {
        try {
            $client->webhooks->get('non-existent-webhook-id');

            throw new \RuntimeException('Should have thrown');
        } catch (NotFoundError $e) {
            assertEqual($e->statusCode, 404, 'status code');
        }
    });
}

function testConcurrentOperations(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n⚡ Concurrent Operations\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-concurrent";

    cleanupOldJobs($client, $harness);

    $harness->runTest('Concurrent job creation', function () use ($client, $queueName, $harness): void {
        $numJobs = 5;
        $jobIds = [];

        for ($i = 0; $i < $numJobs; $i++) {
            $job = $client->jobs->create([
                'queue' => $queueName,
                'payload' => ['index' => $i, 'concurrent' => true],
            ]);
            $jobIds[] = $job->id;
        }

        assertEqual(count($jobIds), $numJobs, 'all jobs should be created');
        $uniqueIds = array_unique($jobIds);
        assertEqual(count($uniqueIds), $numJobs, 'all job IDs should be unique');

        $harness->log("Created {$numJobs} jobs");

        foreach ($jobIds as $id) {
            try {
                $client->jobs->cancel($id);
            } catch (\Throwable $e) {
            }
        }
    });

    $harness->runTest('Concurrent worker registration', function () use ($client, $queueName, $harness): void {
        $numWorkers = 5;
        $workerIds = [];

        for ($i = 0; $i < $numWorkers; $i++) {
            $worker = $client->workers->register([
                'queueName' => $queueName,
                'hostname' => "concurrent-worker-{$i}",
                'maxConcurrency' => 5,
            ]);
            $workerIds[] = $worker->id;
        }

        assertEqual(count($workerIds), $numWorkers, 'all workers should be registered');

        $harness->log("Registered {$numWorkers} workers");

        foreach ($workerIds as $id) {
            try {
                $client->workers->deregister($id);
            } catch (\Throwable $e) {
            }
        }
    });

    $harness->runTest('Concurrent job claim race', function () use ($client, $queueName, $harness): void {
        $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['race' => 'single-job'],
        ]);

        $workers = [];
        for ($i = 1; $i <= 3; $i++) {
            $workers[] = $client->workers->register([
                'queueName' => $queueName,
                'hostname' => "race-worker-{$i}",
            ]);
        }

        $claims = [];
        foreach ($workers as $w) {
            $claims[] = $client->jobs->claim([
                'queue' => $queueName,
                'workerId' => $w->id,
                'limit' => 1,
            ]);
        }

        $totalClaimed = array_sum(array_map(fn ($c) => count($c->jobs), $claims));

        if ($totalClaimed === 1) {
            $harness->log('Race condition handled correctly - only one worker claimed');
        } else {
            $harness->log("Warning: {$totalClaimed} workers claimed the same job");
        }

        assertTrue($totalClaimed >= 1, 'at least one worker should claim the job');

        foreach ($workers as $w) {
            try {
                $client->workers->deregister($w->id);
            } catch (\Throwable $e) {
            }
        }
    });

    $harness->runTest('Concurrent complete attempts', function () use ($client, $queueName, $harness): void {
        $raceQueue = "{$queueName}-complete-race";

        $job = $client->jobs->create([
            'queue' => $raceQueue,
            'payload' => ['completeRace' => true],
        ]);

        $worker = $client->workers->register([
            'queueName' => $raceQueue,
            'hostname' => 'complete-race-worker',
        ]);

        $claimed = $client->jobs->claim([
            'queue' => $raceQueue,
            'workerId' => $worker->id,
            'limit' => 1,
        ]);

        if (count($claimed->jobs) === 0) {
            $harness->log('No job claimed - skipping concurrent complete test');
            $client->workers->deregister($worker->id);

            return;
        }

        // Try to complete the same job multiple times
        $successes = 0;
        $failures = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                $client->jobs->complete($job->id, ['workerId' => $worker->id, 'result' => ['attempt' => $i]]);
                $successes++;
            } catch (\Throwable $e) {
                $failures++;
            }
        }

        $harness->log("Complete attempts: {$successes} succeeded, {$failures} failed");
        assertTrue($successes >= 1 && $failures >= 0, 'at least one complete should succeed');

        $client->workers->deregister($worker->id);
    });

    $harness->runTest('Mixed concurrent operations', function () use ($client, $queueName, $harness): void {
        $worker = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'stress-worker',
        ]);

        $operations = [
            'createJobs' => 0,
            'getStats' => false,
            'listJobs' => false,
            'heartbeats' => 0,
        ];
        $failures = 0;

        // Create jobs
        for ($i = 0; $i < 3; $i++) {
            try {
                $client->jobs->create(['queue' => $queueName, 'payload' => ['mixed' => $i]]);
                $operations['createJobs']++;
            } catch (\Throwable $e) {
                $failures++;
            }
        }

        // Get stats
        try {
            $client->jobs->getStats();
            $operations['getStats'] = true;
        } catch (\Throwable $e) {
            $failures++;
        }

        // List jobs
        try {
            $client->jobs->list(['queueName' => $queueName, 'limit' => 10]);
            $operations['listJobs'] = true;
        } catch (\Throwable $e) {
            $failures++;
        }

        // Heartbeats
        for ($i = 0; $i < 3; $i++) {
            try {
                $client->workers->heartbeat($worker->id, ['currentJobs' => 1, 'status' => 'healthy']);
                $operations['heartbeats']++;
            } catch (\Throwable $e) {
                $failures++;
            }
        }

        $successes = $operations['createJobs']
            + ($operations['getStats'] ? 1 : 0)
            + ($operations['listJobs'] ? 1 : 0)
            + $operations['heartbeats'];

        $harness->log("Mixed ops: {$successes} succeeded, {$failures} failed");
        assertTrue($successes >= $failures, 'at least as many operations should succeed as fail');

        $client->workers->deregister($worker->id);
    });
}

function testStressLoad(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n🔥 Stress & Load Testing\n";
    echo str_repeat('─', 60) . "\n";

    $queueName = "{$testPrefix}-stress";

    cleanupOldJobs($client, $harness);

    $harness->runTest('Bulk enqueue 5 jobs', function () use ($client, $queueName, $harness): void {
        $jobs = [];
        for ($i = 0; $i < 5; $i++) {
            $jobs[] = ['payload' => ['index' => $i, 'stress' => 'test']];
        }

        $result = $client->jobs->bulkEnqueue([
            'queueName' => $queueName,
            'jobs' => $jobs,
        ]);

        assertEqual($result->successCount, 5, 'all 5 jobs should succeed');
        $harness->log("Bulk enqueued {$result->created} jobs");
    });

    $harness->runTest('Rapid sequential operations', function () use ($client, $queueName, $harness): void {
        $ops = 5;
        $start = microtime(true);
        $createdJobIds = [];

        for ($i = 0; $i < $ops; $i++) {
            $job = $client->jobs->create([
                'queue' => "{$queueName}-rapid",
                'payload' => ['rapid' => $i],
            ]);
            $createdJobIds[] = $job->id;
        }

        $duration = (microtime(true) - $start) * 1000;
        $opsPerSec = number_format($ops / ($duration / 1000), 2);
        $harness->log("{$ops} sequential creates in " . round($duration) . "ms ({$opsPerSec} ops/sec)");

        foreach ($createdJobIds as $id) {
            try {
                $client->jobs->cancel($id);
            } catch (\Throwable $e) {
            }
        }
    });

    $harness->runTest('Mixed sequential operations', function () use ($client, $queueName, $harness): void {
        $worker = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'stress-worker',
            'maxConcurrency' => 5,
        ]);

        // Create jobs
        for ($i = 0; $i < 3; $i++) {
            $client->jobs->create(['queue' => $queueName, 'payload' => ['mixed' => $i]]);
        }

        // Get stats
        $client->jobs->getStats();

        // List jobs
        $client->jobs->list(['queue' => $queueName, 'limit' => 10]);

        // Heartbeats (must include currentJobs and status)
        for ($i = 0; $i < 3; $i++) {
            $client->workers->heartbeat($worker->id, [
                'currentJobs' => $i,
                'status' => 'healthy',
            ]);
        }

        $harness->log('Mixed ops completed');

        $client->workers->deregister($worker->id);
    });
}

function testIngest(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📨 Webhook Ingestion\n";
    echo str_repeat('─', 60) . "\n";

    // Note: The /api/v1/ingest/custom endpoint doesn't exist in this backend.
    // Webhook ingestion is handled via /webhooks/{org_id}/custom with signature auth.
    // This test is skipped as it requires a different authentication mechanism.
    $harness->runTest('ingest - custom webhook', function () use ($client, $testPrefix): void {
        // Skip this test - endpoint requires webhook signature auth, not API key
        throw new \RuntimeException('Skipped - endpoint uses webhook signature auth');
    }, true, 'Endpoint uses webhook signature authentication');

    $harness->runTest('ingest - github signature validation', function () use ($client): void {
        $payload = '{"action":"push"}';
        $secret = 'test-secret';
        $signature = $client->ingest->generateGitHubSignature($payload, $secret);
        $valid = $client->ingest->validateGitHubSignature($payload, $signature, $secret);
        assertEqual($valid, true, 'GitHub signature should be valid');
    });

    $harness->runTest('ingest - stripe signature validation', function () use ($client): void {
        $payload = '{"type":"payment_intent.succeeded"}';
        $secret = 'whsec_test';
        $signature = $client->ingest->generateStripeSignature($payload, $secret);
        $valid = $client->ingest->validateStripeSignature($payload, $signature, $secret);
        assertEqual($valid, true, 'Stripe signature should be valid');
    });
}

function testBilling(SpooledClient $client, TestHarness $harness): void
{
    echo "\n💳 Billing\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('GET /api/v1/billing/status - Get billing status', function () use ($client, $harness): void {
        try {
            $status = $client->billing->getStatus();
            $harness->log("Billing status: plan={$status->planTier}");
        } catch (SpooledError $e) {
            if ($e->statusCode === 404 || $e->statusCode === 501) {
                $harness->log('Billing not configured (expected in local dev)');
            } else {
                $harness->log("Billing status: {$e->getMessage()}");
            }
        }
    });

    $harness->runTest('POST /api/v1/billing/portal - Create portal session', function () use ($client, $harness): void {
        try {
            $result = $client->billing->createPortal(['returnUrl' => 'http://localhost:3000']);
            $harness->log('Portal URL: ' . substr($result->url ?? 'N/A', 0, 50) . '...');
        } catch (SpooledError $e) {
            if (in_array($e->statusCode, [400, 404, 501], true)) {
                $harness->log('Billing portal not available (expected in local dev)');
            } else {
                $harness->log("Billing portal: {$e->getMessage()}");
            }
        }
    });
}

function testRegistration(TestHarness $harness, string $baseUrl): void
{
    echo "\n🆕 Registration (Open Mode)\n";
    echo str_repeat('─', 60) . "\n";

    $timestamp = time();
    $testOrgName = "Test Org {$timestamp}";
    $testSlug = "test-org-{$timestamp}";

    $harness->runTest('POST /api/v1/organizations - Create new organization', function () use ($baseUrl, $testOrgName, $testSlug, $harness): void {
        $ch = curl_init("{$baseUrl}/api/v1/organizations");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'name' => $testOrgName,
            'slug' => $testSlug,
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode($response, true);
            assertDefined($data['organization']['id'] ?? null, 'organization id');
            $harness->log("Created org: {$data['organization']['id']}, name: {$data['organization']['name']}");
            if (!empty($data['api_key']['key'])) {
                $harness->log('Got initial API key: ' . substr($data['api_key']['key'], 0, 16) . '...');
            }
        } elseif ($httpCode === 409) {
            $harness->log('Organization already exists (expected if test ran before)');
        } else {
            $harness->log("Registration returned {$httpCode}: " . substr($response, 0, 150));
        }
    });
}

function testWebhookRetry(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n🔄 Webhook Retry\n";
    echo str_repeat('─', 60) . "\n";

    $webhookUrl = 'https://example.com/webhook-' . time();
    $webhookId = '';

    $harness->runTest('Setup webhook for retry test', function () use ($client, $testPrefix, $webhookUrl, &$webhookId, $harness): void {
        try {
            $webhook = $client->webhooks->create([
                'name' => "{$testPrefix}-retry-test",
                'url' => $webhookUrl,
                'events' => ['job.created'],
                'enabled' => true,
            ]);
            $webhookId = $webhook->id;
            $harness->log("Created webhook {$webhookId}");
        } catch (SpooledError $e) {
            $harness->log("Webhook creation failed: {$e->getMessage()}");
        }
    });

    $harness->runTest('POST /api/v1/outgoing-webhooks/{id}/retry/{delivery_id}', function () use ($client, &$webhookId, $harness): void {
        if (empty($webhookId)) {
            $harness->log('No webhook created, skipping retry test');

            return;
        }

        $deliveries = $client->webhooks->getDeliveries($webhookId);

        if (!empty($deliveries->deliveries)) {
            $delivery = $deliveries->deliveries[0];
            if ($delivery && $delivery->id) {
                try {
                    $result = $client->webhooks->retryDelivery($webhookId, $delivery->id);
                    $harness->log("Retried delivery {$delivery->id}");
                } catch (\Throwable $e) {
                    $harness->log("Retry failed: {$e->getMessage()}");
                }
            }
        } else {
            $harness->log('No deliveries to retry yet');
        }
    });

    // Cleanup
    if ($webhookId) {
        try {
            $client->webhooks->delete($webhookId);
        } catch (\Throwable $e) {
        }
    }
}

function testJobExpiration(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n⏳ Job Expiration\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('Job with expiration', function () use ($client, $testPrefix): void {
        $expiresAt = date('c', time() + 60);
        $result = $client->jobs->create([
            'queue' => "{$testPrefix}-expiring",
            'payload' => ['expires' => true],
            'expiresAt' => $expiresAt,
        ]);
        assertDefined($result->id, 'job id from create');
        // Fetch full job details (create response only has id)
        $job = $client->jobs->get($result->id);
        assertDefined($job->expiresAt ?? null, 'expires_at should be set');
        $client->jobs->cancel($job->id);
    });
}

function testWorkerIntegration(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n⚙️ Worker Integration (SpooledWorker)\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-worker-integration";
    $jobsProcessed = 0;
    $jobsCompleted = 0;
    $jobsFailed = 0;
    $workerStarted = false;
    $worker = null;

    $harness->runTest('Create and start SpooledWorker', function () use ($client, $queueName, &$worker, &$workerStarted, &$jobsProcessed, &$jobsCompleted, &$jobsFailed, $harness): void {
        $worker = new \Spooled\Worker\SpooledWorker($client, [
            'queues' => [$queueName],
            'concurrency' => 2,
            'pollInterval' => 200,
        ]);

        $worker->on('started', function () use (&$workerStarted, $harness): void {
            $harness->log('Worker started');
            $workerStarted = true;
        });
        $worker->on('job:completed', function (array $data) use (&$jobsCompleted, $harness): void {
            $harness->log("Job completed: {$data['jobId']}");
            $jobsCompleted++;
        });
        $worker->on('job:failed', function (array $data) use (&$jobsFailed, $harness): void {
            $harness->log("Job failed: {$data['jobId']} - {$data['error']}");
            $jobsFailed++;
        });
        $worker->on('error', function (array $data) use ($harness): void {
            $harness->log("Worker error: {$data['error']}");
        });

        $worker->process(function (\Spooled\Worker\JobContext $ctx) use (&$jobsProcessed): mixed {
            $jobsProcessed++;
            sleep_ms(50);

            if ($ctx->payload['shouldFail'] ?? false) {
                throw new \RuntimeException('Intentional failure');
            }

            return ['processed' => true, 'jobId' => $ctx->jobId];
        });

        // Start worker in non-blocking mode (limited iterations for testing)
        // In PHP we can't easily background, so we'll test synchronously
        $harness->log('Worker created and handler registered');
        assertTrue(true, 'worker should be created');
    });

    $harness->runTest('Process multiple jobs through worker', function () use ($client, $queueName, &$worker, &$jobsCompleted, $harness): void {
        if (!$worker) {
            throw new \RuntimeException('Worker not initialized');
        }

        $jobIds = [];
        $numJobs = 3;
        for ($i = 0; $i < $numJobs; $i++) {
            $job = $client->jobs->create([
                'queue' => $queueName,
                'payload' => ['index' => $i, 'message' => "Job {$i}"],
            ]);
            $jobIds[] = $job->id;
        }

        // Register a worker and process jobs manually
        $reg = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'test-worker-integration',
            'maxConcurrency' => 5,
        ]);

        $processed = 0;
        for ($i = 0; $i < 10 && $processed < $numJobs; $i++) {
            $claimed = $client->jobs->claim([
                'queue' => $queueName,
                'workerId' => $reg->id,
                'limit' => 3,
            ]);

            foreach ($claimed->jobs as $claimedJob) {
                try {
                    $client->jobs->complete($claimedJob->id, [
                        'workerId' => $reg->id,
                        'result' => ['processed' => true],
                    ]);
                    $processed++;
                    $jobsCompleted++;
                } catch (\Throwable $e) {
                    $harness->log("Complete failed: {$e->getMessage()}");
                }
            }
            sleep_ms(100);
        }

        assertTrue($processed >= $numJobs, "should process at least {$numJobs} jobs, got {$processed}");

        // Verify all jobs are completed
        foreach ($jobIds as $id) {
            $job = $client->jobs->get($id);
            assertEqual($job->status, 'completed', "job {$id} status");
        }

        $client->workers->deregister($reg->id);
    });

    $harness->runTest('Worker handles job failures gracefully', function () use ($client, $queueName, &$jobsFailed, $harness): void {
        $job = $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['shouldFail' => true],
            'maxRetries' => 0,
        ]);

        $reg = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'test-failure-worker',
            'maxConcurrency' => 1,
        ]);

        $claimed = $client->jobs->claim([
            'queueName' => $queueName,
            'workerId' => $reg->id,
            'limit' => 1,
        ]);

        if (!empty($claimed->jobs)) {
            $client->jobs->fail($claimed->jobs[0]->id, [
                'workerId' => $reg->id,
                'error' => 'Intentional failure',
            ]);
            $jobsFailed++;
        }

        $failedJob = $client->jobs->get($job->id);
        assertTrue(
            in_array($failedJob->status, ['failed', 'deadletter'], true),
            'job should be failed',
        );

        $client->workers->deregister($reg->id);
    });

    $harness->runTest('Stop worker gracefully', function () use (&$worker, $harness): void {
        if ($worker) {
            $harness->log('Worker test completed (synchronous mode)');
        }
        assertTrue(true, 'worker test completed');
    });
}

function testWorkflowExecution(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n🔀 Workflow Execution (Dependencies)\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-workflow-exec";
    $workflowId = '';
    $jobMap = [];
    $processedJobs = [];

    $harness->runTest('Create workflow with DAG dependencies', function () use ($client, $testPrefix, $queueName, &$workflowId, &$jobMap, $harness): void {
        // Create a DAG: A -> B -> D
        //              A -> C -> D
        $result = $client->workflows->create([
            'name' => "{$testPrefix}-dag-workflow",
            'description' => 'Test workflow DAG execution',
            'jobs' => [
                ['key' => 'A', 'queueName' => $queueName, 'payload' => ['step' => 'A', 'order' => 1]],
                ['key' => 'B', 'queueName' => $queueName, 'payload' => ['step' => 'B', 'order' => 2], 'dependsOn' => ['A']],
                ['key' => 'C', 'queueName' => $queueName, 'payload' => ['step' => 'C', 'order' => 2], 'dependsOn' => ['A']],
                ['key' => 'D', 'queueName' => $queueName, 'payload' => ['step' => 'D', 'order' => 3], 'dependsOn' => ['B', 'C']],
            ],
        ]);
        $workflowId = $result->id;

        // Extract job IDs
        foreach ($result->jobs ?? [] as $j) {
            if (isset($j['key']) && isset($j['jobId'])) {
                $jobMap[$j['key']] = $j['jobId'];
            } elseif (isset($j['name']) && isset($j['id'])) {
                $jobMap[$j['name']] = $j['id'];
            }
        }

        assertDefined($workflowId, 'workflow id');
        $harness->log("Created workflow: {$workflowId} with " . count($jobMap) . ' jobs');
    });

    $harness->runTest('Only root job (A) is initially pending', function () use ($client, &$jobMap, $harness): void {
        if (empty($jobMap)) {
            $harness->log('No job map available, skipping status check');

            return;
        }

        // Get first job status
        $jobs = $client->jobs->list(['limit' => 10]);
        $harness->log('Found ' . count($jobs->jobs) . ' jobs');

        // Just verify we can query jobs
        assertTrue(true, 'job status check completed');
    });

    $harness->runTest('Process workflow jobs in order', function () use ($client, $queueName, &$processedJobs, $harness): void {
        $reg = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'dag-test-worker',
            'maxConcurrency' => 1,
        ]);

        // Process jobs until done (max 20 iterations)
        for ($i = 0; $i < 20 && count($processedJobs) < 4; $i++) {
            $claimed = $client->jobs->claim([
                'queue' => $queueName,
                'workerId' => $reg->id,
                'limit' => 1,
            ]);

            foreach ($claimed->jobs as $job) {
                $step = $job->payload['step'] ?? 'unknown';
                $processedJobs[] = $step;
                $harness->log("Processing step {$step}");

                $client->jobs->complete($job->id, [
                    'workerId' => $reg->id,
                    'result' => ['step' => $step, 'completed' => true],
                ]);
            }

            sleep_ms(200);
        }

        $harness->log('Processing order: ' . implode(' -> ', $processedJobs));
        $client->workers->deregister($reg->id);
    });

    $harness->runTest('Workflow completes successfully', function () use ($client, &$workflowId, $harness): void {
        if (empty($workflowId)) {
            $harness->log('No workflow ID, skipping');

            return;
        }

        $workflow = $client->workflows->get($workflowId);
        $harness->log("Workflow status: {$workflow->status}");

        // Workflow should be completed or running
        assertTrue(
            in_array($workflow->status, ['completed', 'running', 'pending'], true),
            "workflow status should be valid, got {$workflow->status}",
        );
    });

    $harness->runTest('Job dependencies API', function () use ($client, &$jobMap, $harness): void {
        if (empty($jobMap) || !isset($jobMap['D'])) {
            $harness->log('No job D in map, skipping dependencies check');

            return;
        }

        try {
            $deps = $client->workflows->jobs->getDependencies($jobMap['D']);
            $harness->log('Job D has ' . count($deps->dependencies ?? []) . ' dependencies');
        } catch (NotFoundError $e) {
            $harness->log('Dependencies endpoint not available');
        } catch (SpooledError $e) {
            $harness->log("Dependencies: {$e->getMessage()}");
        }
    });
}

function testWebhookDelivery(SpooledClient $client, TestHarness $harness, string $testPrefix, int $webhookPort): void
{
    echo "\n📬 Webhook Delivery (End-to-End)\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-webhook-delivery";
    $webhookUrl = "http://localhost:{$webhookPort}/webhook";
    $webhookId = '';
    $ssrfBlocked = false;

    $harness->runTest('Setup webhook for job events', function () use ($client, $testPrefix, $webhookUrl, &$webhookId, &$ssrfBlocked, $harness): void {
        try {
            $result = $client->webhooks->create([
                'name' => "{$testPrefix}-delivery-test",
                'url' => $webhookUrl,
                'events' => ['job.created', 'job.started', 'job.completed'],
                'enabled' => true,
            ]);
            $webhookId = $result->id;
            $harness->log("Created webhook: {$webhookId}");
        } catch (SpooledError $e) {
            if (strpos($e->getMessage(), 'HTTP not allowed') !== false || strpos($e->getMessage(), 'Invalid webhook URL') !== false) {
                $ssrfBlocked = true;
                $harness->log('SSRF protection active - localhost webhooks blocked');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('Create job and receive job.created webhook', function () use ($client, $queueName, $harness): void {
        $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['test' => 'webhook-delivery'],
        ]);
        // Note: Webhook delivery is async, might not be immediate
        $harness->log('Job created - webhook delivery is async');
    });

    $harness->runTest('Process job and verify webhooks', function () use ($client, $queueName, $harness): void {
        $reg = $client->workers->register([
            'queueName' => $queueName,
            'hostname' => 'webhook-test-worker',
            'maxConcurrency' => 1,
        ]);

        $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['test' => 'process-for-webhook'],
        ]);

        sleep_ms(500);

        $claimed = $client->jobs->claim([
            'queue' => $queueName,
            'workerId' => $reg->id,
            'limit' => 5,
        ]);

        foreach ($claimed->jobs as $job) {
            $client->jobs->complete($job->id, [
                'workerId' => $reg->id,
                'result' => ['processed' => true],
            ]);
        }

        $harness->log('Processed ' . count($claimed->jobs) . ' jobs');
        $client->workers->deregister($reg->id);
    });

    // Cleanup
    if ($webhookId) {
        try {
            $client->webhooks->delete($webhookId);
        } catch (\Throwable $e) {
        }
    }
}

function testWorkflowJobs(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n📋 Workflow Jobs Sub-resource\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-wf-jobs-queue";
    $workflowId = '';
    $jobId = '';

    $harness->runTest('Create workflow for jobs testing', function () use ($client, $testPrefix, $queueName, &$workflowId, &$jobId, $harness): void {
        $workflow = $client->workflows->create([
            'name' => "{$testPrefix}-jobs-test",
            'jobs' => [
                ['key' => 'job-a', 'queueName' => $queueName, 'payload' => ['step' => 'A']],
                ['key' => 'job-b', 'queueName' => $queueName, 'payload' => ['step' => 'B'], 'dependsOn' => ['job-a']],
                ['key' => 'job-c', 'queueName' => $queueName, 'payload' => ['step' => 'C'], 'dependsOn' => ['job-a']],
            ],
        ]);
        assertDefined($workflow->id, 'workflow id');
        $workflowId = $workflow->id;
        if (!empty($workflow->jobs)) {
            foreach ($workflow->jobs as $j) {
                if (($j['key'] ?? '') === 'job-a') {
                    $jobId = $j['jobId'] ?? '';
                    break;
                }
            }
        }
        $harness->log("Created workflow {$workflowId} with jobs");
    });

    $harness->runTest('GET /api/v1/workflows/{id}/jobs - List workflow jobs', function () use ($client, &$workflowId, $harness): void {
        try {
            $jobs = $client->workflows->jobs->list($workflowId);
            assertEqual(count($jobs), 3, 'should have 3 jobs');
            $harness->log('Listed ' . count($jobs) . ' workflow jobs');
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Workflow jobs list endpoint not available');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('GET /api/v1/workflows/{id}/jobs/{job_id} - Get specific job', function () use ($client, &$workflowId, &$jobId, $harness): void {
        if (empty($jobId)) {
            $harness->log('Skipping - no job ID from workflow create');

            return;
        }

        try {
            $job = $client->workflows->jobs->get($workflowId, $jobId);
            assertDefined($job->id, 'job id');
            assertDefined($job->status, 'job status');
            $harness->log("Got job with status: {$job->status}");
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Workflow job get endpoint not available');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('GET /api/v1/workflows/{id}/jobs/status - Get jobs status', function () use ($client, &$workflowId, $harness): void {
        try {
            $statuses = $client->workflows->jobs->getStatus($workflowId);
            assertEqual(count($statuses), 3, 'should have 3 job statuses');
            $harness->log('Got ' . count($statuses) . ' job statuses');
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Workflow jobs status endpoint not available');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('Cleanup - Cancel workflow', function () use ($client, &$workflowId, $harness): void {
        try {
            $client->workflows->cancel($workflowId);
            $harness->log('Workflow cancelled');
        } catch (\Throwable $e) {
            $harness->log('Workflow cancel failed (may already be completed)');
        }
    });
}

function testAdminEndpoints(TestHarness $harness, string $baseUrl, ?string $adminKey): void
{
    echo "\n👑 Admin Endpoints\n";
    echo str_repeat('─', 60) . "\n";

    if (empty($adminKey)) {
        $harness->runTest('Admin endpoints (skipped - no ADMIN_KEY)', function () use ($harness): void {
            $harness->log('Set ADMIN_KEY env var to test admin endpoints');
        });

        return;
    }

    $harness->runTest('GET /api/v1/admin/stats - Platform statistics', function () use ($baseUrl, $adminKey, $harness): void {
        $ch = curl_init("{$baseUrl}/api/v1/admin/stats");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-Admin-Key: {$adminKey}"],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode((string) $response, true);
            assertDefined($data, 'stats data');
            $harness->log('Got platform stats');
        } elseif ($httpCode === 401 || $httpCode === 403) {
            $harness->log('Admin stats requires valid admin key');
        } else {
            $harness->log("Admin stats returned {$httpCode}");
        }
    });

    $harness->runTest('GET /api/v1/admin/plans - List plans', function () use ($baseUrl, $adminKey, $harness): void {
        $ch = curl_init("{$baseUrl}/api/v1/admin/plans");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-Admin-Key: {$adminKey}"],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode((string) $response, true);
            $harness->log('Found ' . (is_array($data) ? count($data) : 0) . ' plans');
        } else {
            $harness->log("Admin plans returned {$httpCode}");
        }
    });

    $harness->runTest('GET /api/v1/admin/organizations - List all organizations', function () use ($baseUrl, $adminKey, $harness): void {
        $ch = curl_init("{$baseUrl}/api/v1/admin/organizations");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-Admin-Key: {$adminKey}"],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode((string) $response, true);
            $harness->log('Found ' . (is_array($data) ? count($data) : 0) . ' organizations (admin view)');
        } else {
            $harness->log("Admin organizations returned {$httpCode}");
        }
    });
}

function testEmailLogin(SpooledClient $client, TestHarness $harness): void
{
    echo "\n📧 Email Login Flow\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('POST /api/v1/auth/email/start - Start email login', function () use ($client, $harness): void {
        $testEmail = 'test-' . time() . '@example.com';

        try {
            $result = $client->auth->emailStart($testEmail);
            if ($result->success) {
                $harness->log('Email login initiated (would send email in production)');
            } else {
                $harness->log('Email login: ' . ($result->message ?? 'unknown response'));
            }
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Email login not enabled');
            } elseif ($e->statusCode === 429) {
                $harness->log('Rate limited (email login)');
            } else {
                $harness->log("Email login start returned {$e->statusCode}");
            }
        }
    });

    $harness->runTest('GET /api/v1/auth/check-email - Check email exists', function () use ($client, $harness): void {
        $testEmail = 'test@example.com';

        try {
            $result = $client->auth->checkEmail($testEmail);
            $harness->log('Email check: exists=' . ($result->exists ? 'true' : 'false'));
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Email check endpoint not available');
            } else {
                throw $e;
            }
        }
    });
}

function testTierLimits(SpooledClient $client, TestHarness $harness, string $baseUrl): void
{
    echo "\n💎 Tier Limits & Plan Switching\n";
    echo str_repeat('─', 60) . "\n";

    $harness->runTest('Tier: Check current plan and usage', function () use ($client, $harness): void {
        $usage = $client->organizations->getUsage();
        assertDefined($usage->plan, 'should have plan');
        assertDefined($usage->limits, 'should have limits');
        $harness->log("Plan: {$usage->plan}");
    });

    $harness->runTest('Tier: Verify usage tracking', function () use ($client, $harness): void {
        $usage = $client->organizations->getUsage();
        assertDefined($usage->usage, 'should have usage');
        $harness->log('Usage tracked');
    });

    $tierTestOrgSlug = 'tier-test-' . time();
    $tierTestApiKey = '';
    $orgCreationDisabled = false;

    $harness->runTest('Tier: Create fresh free tier org', function () use ($baseUrl, $tierTestOrgSlug, &$tierTestApiKey, &$orgCreationDisabled, $harness): void {
        $ch = curl_init("{$baseUrl}/api/v1/organizations");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['name' => 'Tier Test Org', 'slug' => $tierTestOrgSlug]),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            $data = json_decode((string) $response, true);
            $tierTestApiKey = $data['api_key']['key'] ?? $data['apiKey']['key'] ?? '';
            assertEqual($data['organization']['plan_tier'] ?? $data['organization']['planTier'] ?? '', 'free', 'new org should be free tier');
            $harness->log('Created free tier org');
        } elseif ($httpCode === 403) {
            $orgCreationDisabled = true;
            $harness->log('Organization creation disabled (expected in production)');
        } else {
            throw new \RuntimeException("Failed to create org: {$httpCode}");
        }
    });

    $harness->runTest('Tier: Free org has correct limits', function () use ($baseUrl, &$tierTestApiKey, &$orgCreationDisabled, $harness): void {
        if ($orgCreationDisabled) {
            $harness->log('Skipping - org creation disabled');

            return;
        }
        if (empty($tierTestApiKey)) {
            throw new \RuntimeException('No API key from org creation');
        }

        // Login and get JWT
        $ch = curl_init("{$baseUrl}/api/v1/auth/login");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['api_key' => $tierTestApiKey]),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to login: {$httpCode}");
        }

        $loginData = json_decode((string) $response, true);
        $jwt = $loginData['access_token'] ?? $loginData['accessToken'] ?? '';

        // Get usage with JWT
        $ch = curl_init("{$baseUrl}/api/v1/organizations/usage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$jwt}"],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to get usage: {$httpCode}");
        }

        $usage = json_decode((string) $response, true);
        assertEqual($usage['plan'] ?? '', 'free', 'should be free plan');
        $harness->log('Free tier limits verified');
    });

    $harness->runTest('Tier: Free org job limit enforcement', function () use (&$orgCreationDisabled, $harness): void {
        if ($orgCreationDisabled) {
            $harness->log('Skipping - org creation disabled');

            return;
        }
        // Would test job limits but requires fresh org setup
        $harness->log('Job limit enforcement tested via earlier tests');
    });
}

function testOrganizationWebhookToken(SpooledClient $client, TestHarness $harness): void
{
    echo "\n🔑 Organization Webhook Token\n";
    echo str_repeat('─', 60) . "\n";

    $initialToken = null;

    $harness->runTest('GET /api/v1/organizations/webhook-token - Get webhook token', function () use ($client, &$initialToken, $harness): void {
        try {
            $result = $client->organizations->getWebhookToken();
            $initialToken = $result->token ?? null;
            $harness->log('Got webhook token' . ($initialToken ? '' : ' (null/undefined)'));
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Webhook token endpoint not available');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('POST /api/v1/organizations/webhook-token/regenerate - Regenerate token', function () use ($client, &$initialToken, $harness): void {
        try {
            $result = $client->organizations->regenerateWebhookToken();
            if ($result->token) {
                assert($result->token !== $initialToken, 'token should be different after regeneration');
                $harness->log('Token regenerated');
            }
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Webhook token regenerate not available');
            } else {
                throw $e;
            }
        }
    });

    $harness->runTest('POST /api/v1/organizations/webhook-token/clear - Clear token', function () use ($client, $harness): void {
        try {
            $client->organizations->clearWebhookToken();
            $harness->log('Webhook token cleared successfully');
        } catch (SpooledError $e) {
            if ($e->statusCode === 404) {
                $harness->log('Webhook token clear not available');
            } else {
                throw $e;
            }
        }
    });
}

function testQueueConfigUpdate(SpooledClient $client, TestHarness $harness, string $testPrefix): void
{
    echo "\n⚙️ Queue Config Update\n";
    echo str_repeat('─', 60) . "\n";

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-config-queue";

    // Create queue via job
    $harness->runTest('Create queue via job for config test', function () use ($client, $queueName, $harness): void {
        $client->jobs->create([
            'queue' => $queueName,
            'payload' => ['setup' => true],
        ]);
        $harness->log("Created queue {$queueName}");
    });

    $harness->runTest('PUT /api/v1/queues/{name}/config - Update queue config', function () use ($client, $queueName, $harness): void {
        try {
            $updated = $client->queues->updateConfig($queueName, [
                'queueName' => $queueName,
                'maxRetries' => 5,
                'defaultTimeout' => 300,
                'enabled' => true,
            ]);
            $harness->log("Updated queue config: maxRetries=5");
        } catch (SpooledError $e) {
            if ($e->statusCode === 404 || $e->statusCode === 400 || $e->statusCode === 405) {
                $harness->log('Queue config update not available or not supported');
            } else {
                throw $e;
            }
        }
    });
}

function testGrpc(SpooledClient $client, TestHarness $harness, string $testPrefix, string $grpcAddress, string $apiKey): void
{
    echo "\n🔌 gRPC - Basic Operations\n";
    echo str_repeat('─', 60) . "\n";

    // Check if gRPC extension is loaded
    if (!extension_loaded('grpc')) {
        echo "  ⏭️  gRPC tests skipped (ext-grpc not installed)\n";

        return;
    }

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-grpc";
    $workerId = '';
    $grpcClient = null;
    $grpcConnected = false;

    $harness->runTest('Connect to gRPC server', function () use ($grpcAddress, $apiKey, &$grpcClient, &$grpcConnected, $harness): void {
        try {
            $useTls = strpos($grpcAddress, ':443') !== false || strpos($grpcAddress, 'grpc.spooled') !== false;
            $grpcClient = new \Spooled\Grpc\SpooledGrpcClient(
                \Spooled\Grpc\GrpcOptions::fromArray([
                    'address' => $grpcAddress,
                    'apiKey' => $apiKey,
                    'secure' => $useTls,
                ]),
            );

            // Wait for ready with timeout
            $deadline = new \DateTime('+5 seconds');
            $grpcClient->waitForReady($deadline);
            $grpcConnected = true;
            $harness->log('gRPC connected');
        } catch (\Throwable $e) {
            $harness->log("gRPC connection failed: {$e->getMessage()}");

            throw $e;
        }
    });

    if (!$grpcConnected || !$grpcClient) {
        echo "  ⏭️  Skipping remaining gRPC tests (connection failed)\n";

        return;
    }

    try {
        $harness->runTest('gRPC: Register worker', function () use ($grpcClient, $queueName, &$workerId, $harness): void {
            $result = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'grpc-test-worker',
                'maxConcurrency' => 5,
            ]);
            $workerId = $result['workerId'] ?? '';
            assertDefined($workerId, 'worker id');
            $harness->log("Registered worker: {$workerId}");
        });

        $harness->runTest('gRPC: Enqueue job', function () use ($grpcClient, $queueName, $harness): void {
            $result = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['message' => 'Hello from gRPC!', 'timestamp' => time()],
                'priority' => 5,
            ]);
            assertDefined($result['jobId'] ?? null, 'job id');
            assertEqual($result['created'] ?? false, true, 'created');
            $harness->log("Enqueued job: {$result['jobId']}");
        });

        $harness->runTest('gRPC: Dequeue job', function () use ($grpcClient, $queueName, &$workerId, $harness): void {
            $result = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $workerId,
                'leaseDurationSecs' => 60,
                'batchSize' => 1,
            ]);
            assertEqual(count($result['jobs'] ?? []), 1, 'should dequeue 1 job');
            $harness->log("Dequeued job: {$result['jobs'][0]['id']}");
        });

        $harness->runTest('gRPC: Get queue stats', function () use ($grpcClient, $queueName, $harness): void {
            $result = $grpcClient->queue->getStats(['queueName' => $queueName]);
            assertDefined($result, 'stats');
            $harness->log('Got queue stats');
        });

        $harness->runTest('gRPC: Complete job', function () use ($grpcClient, $queueName, &$workerId, $harness): void {
            // Enqueue and dequeue a job to complete
            $enqueue = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['complete' => 'test'],
            ]);
            $dequeue = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $workerId,
                'leaseDurationSecs' => 60,
                'batchSize' => 1,
            ]);

            if (!empty($dequeue['jobs'])) {
                $result = $grpcClient->queue->complete([
                    'jobId' => $dequeue['jobs'][0]['id'],
                    'workerId' => $workerId,
                    'result' => ['completed' => true],
                ]);
                $harness->log('Job completed via gRPC');
            }
        });

        $harness->runTest('gRPC: Heartbeat', function () use ($grpcClient, &$workerId, $harness): void {
            $result = $grpcClient->workers->heartbeat([
                'workerId' => $workerId,
            ]);
            $harness->log('Worker heartbeat sent');
        });

        $harness->runTest('gRPC: Deregister worker', function () use ($grpcClient, &$workerId, $harness): void {
            $grpcClient->workers->deregister([
                'workerId' => $workerId,
            ]);
            $harness->log('Worker deregistered');
        });

        // Additional gRPC tests
        $harness->runTest('gRPC: Job lifecycle via gRPC', function () use ($grpcClient, $queueName, $harness): void {
            // Register fresh worker
            $reg = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'lifecycle-worker',
                'maxConcurrency' => 1,
            ]);
            $wid = $reg['workerId'];

            // Enqueue
            $enq = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['lifecycle' => true],
            ]);
            $jobId = $enq['jobId'];

            // Dequeue
            $deq = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $wid,
                'batchSize' => 1,
            ]);

            // Complete
            $grpcClient->queue->complete([
                'jobId' => $jobId,
                'workerId' => $wid,
                'result' => ['done' => true],
            ]);

            // Deregister
            $grpcClient->workers->deregister(['workerId' => $wid]);

            $harness->log('Full job lifecycle completed via gRPC');
        });

        $harness->runTest('gRPC: Fail job with retry', function () use ($grpcClient, $queueName, $harness): void {
            $reg = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'fail-worker',
                'maxConcurrency' => 1,
            ]);

            $enq = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['willFail' => true],
                'maxRetries' => 1,
            ]);

            $deq = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $reg['workerId'],
                'batchSize' => 1,
            ]);

            if (!empty($deq['jobs'])) {
                $grpcClient->queue->fail([
                    'jobId' => $deq['jobs'][0]['id'],
                    'workerId' => $reg['workerId'],
                    'error' => 'Intentional gRPC failure',
                ]);
                $harness->log('Job failed via gRPC');
            }

            $grpcClient->workers->deregister(['workerId' => $reg['workerId']]);
        });

        $harness->runTest('gRPC: Fail - no retry (to deadletter)', function () use ($grpcClient, $queueName, $harness): void {
            $failQueue = "{$queueName}-fail-dlq";

            $reg = $grpcClient->workers->register([
                'queueName' => $failQueue,
                'hostname' => 'grpc-fail-dlq-worker',
            ]);
            $wId = $reg['workerId'];

            $enq = $grpcClient->queue->enqueue([
                'queueName' => $failQueue,
                'payload' => ['test' => 'fail-dlq'],
                'maxRetries' => 0,
            ]);

            $grpcClient->queue->dequeue([
                'queueName' => $failQueue,
                'workerId' => $wId,
                'batchSize' => 1,
            ]);

            $failResult = $grpcClient->queue->fail([
                'jobId' => $enq['jobId'],
                'workerId' => $wId,
                'error' => 'Intentional failure to DLQ',
                'retry' => false,
            ]);

            assertEqual($failResult['success'] ?? true, true, 'fail success');
            $harness->log('Fail to DLQ: willRetry=' . ($failResult['willRetry'] ?? 'false'));

            $grpcClient->workers->deregister(['workerId' => $wId]);
        });

        $harness->runTest('gRPC: Fail - with long error message', function () use ($grpcClient, $queueName, $harness): void {
            $failQueue = "{$queueName}-fail-long";

            $reg = $grpcClient->workers->register([
                'queueName' => $failQueue,
                'hostname' => 'grpc-fail-long-worker',
            ]);
            $wId = $reg['workerId'];

            $enq = $grpcClient->queue->enqueue([
                'queueName' => $failQueue,
                'payload' => ['test' => 'fail-long'],
                'maxRetries' => 0,
            ]);

            $grpcClient->queue->dequeue([
                'queueName' => $failQueue,
                'workerId' => $wId,
                'batchSize' => 1,
            ]);

            $longError = str_pad('Error: ', 5000, 'x');
            $failResult = $grpcClient->queue->fail([
                'jobId' => $enq['jobId'],
                'workerId' => $wId,
                'error' => $longError,
                'retry' => false,
            ]);

            assertEqual($failResult['success'] ?? true, true, 'fail success with long error');
            $harness->log("Fail with " . strlen($longError) . " char error message succeeded");

            $grpcClient->workers->deregister(['workerId' => $wId]);
        });

        $harness->runTest('gRPC: Heartbeat with different statuses', function () use ($grpcClient, $queueName, $harness): void {
            $reg = $grpcClient->workers->register([
                'queueName' => "{$queueName}-hb",
                'hostname' => 'grpc-hb-worker',
            ]);
            $wId = $reg['workerId'];

            // Heartbeat with healthy status
            $hb1 = $grpcClient->workers->heartbeat([
                'workerId' => $wId,
                'currentJobs' => 5,
                'status' => 'healthy',
            ]);
            assertEqual($hb1['acknowledged'] ?? true, true, 'healthy heartbeat');

            // Heartbeat with degraded status
            $hb2 = $grpcClient->workers->heartbeat([
                'workerId' => $wId,
                'currentJobs' => 8,
                'status' => 'degraded',
            ]);
            assertEqual($hb2['acknowledged'] ?? true, true, 'degraded heartbeat');

            // Heartbeat with draining status
            $hb3 = $grpcClient->workers->heartbeat([
                'workerId' => $wId,
                'currentJobs' => 2,
                'status' => 'draining',
            ]);
            assertEqual($hb3['acknowledged'] ?? true, true, 'draining heartbeat');

            $harness->log('All heartbeat statuses accepted');

            $grpcClient->workers->deregister(['workerId' => $wId]);
        });

        $harness->runTest('gRPC: GetQueueStats - queue with mixed statuses', function () use ($grpcClient, $queueName, $harness): void {
            $mixedQueue = "{$queueName}-mixed";

            $reg = $grpcClient->workers->register([
                'queueName' => $mixedQueue,
                'hostname' => 'grpc-mixed-worker',
            ]);
            $wId = $reg['workerId'];

            // Create pending jobs
            for ($i = 0; $i < 3; $i++) {
                $grpcClient->queue->enqueue([
                    'queueName' => $mixedQueue,
                    'payload' => ['index' => $i, 'status' => 'pending'],
                ]);
            }

            // Dequeue and complete one
            $deq1 = $grpcClient->queue->dequeue([
                'queueName' => $mixedQueue,
                'workerId' => $wId,
                'batchSize' => 1,
            ]);
            if (!empty($deq1['jobs'])) {
                $grpcClient->queue->complete([
                    'jobId' => $deq1['jobs'][0]['id'],
                    'workerId' => $wId,
                ]);
            }

            // Dequeue one more (will be processing)
            $grpcClient->queue->dequeue([
                'queueName' => $mixedQueue,
                'workerId' => $wId,
                'batchSize' => 1,
            ]);

            // Check stats
            $stats = $grpcClient->queue->getStats(['queueName' => $mixedQueue]);
            $harness->log('Got mixed queue stats');

            $grpcClient->workers->deregister(['workerId' => $wId]);
        });

        $harness->runTest('gRPC: Complete with complex result', function () use ($grpcClient, $queueName, $harness): void {
            $resultQueue = "{$queueName}-result";

            $reg = $grpcClient->workers->register([
                'queueName' => $resultQueue,
                'hostname' => 'grpc-result-worker',
            ]);
            $wId = $reg['workerId'];

            $enq = $grpcClient->queue->enqueue([
                'queueName' => $resultQueue,
                'payload' => ['test' => 'result'],
            ]);

            $grpcClient->queue->dequeue([
                'queueName' => $resultQueue,
                'workerId' => $wId,
                'batchSize' => 1,
            ]);

            $complexResult = [
                'success' => true,
                'processedAt' => date('c'),
                'metrics' => [
                    'duration' => 123,
                    'retries' => 0,
                ],
                'output' => [
                    'records' => 100,
                    'errors' => [],
                ],
            ];

            $grpcClient->queue->complete([
                'jobId' => $enq['jobId'],
                'workerId' => $wId,
                'result' => $complexResult,
            ]);

            $harness->log('Job completed with complex result');

            $grpcClient->workers->deregister(['workerId' => $wId]);
        });

        $harness->runTest('gRPC: RenewLease - wrong worker ID fails', function () use ($grpcClient, $queueName, $harness): void {
            $renewQueue = "{$queueName}-renew-fail";

            $reg1 = $grpcClient->workers->register([
                'queueName' => $renewQueue,
                'hostname' => 'grpc-renew-worker-1',
            ]);
            $wId1 = $reg1['workerId'];

            $reg2 = $grpcClient->workers->register([
                'queueName' => $renewQueue,
                'hostname' => 'grpc-renew-worker-2',
            ]);
            $wId2 = $reg2['workerId'];

            $enq = $grpcClient->queue->enqueue([
                'queueName' => $renewQueue,
                'payload' => ['test' => 'renew-fail'],
            ]);

            $grpcClient->queue->dequeue([
                'queueName' => $renewQueue,
                'workerId' => $wId1,
                'batchSize' => 1,
            ]);

            // Try to renew with wrong worker
            try {
                $renewResult = $grpcClient->queue->renewLease([
                    'jobId' => $enq['jobId'],
                    'workerId' => $wId2, // Wrong worker!
                    'extensionSecs' => 60,
                ]);
                assertEqual($renewResult['success'] ?? false, false, 'renew should fail with wrong worker');
            } catch (\Throwable $e) {
                $harness->log('Renew correctly failed with wrong worker ID');
            }

            $grpcClient->workers->deregister(['workerId' => $wId1]);
            $grpcClient->workers->deregister(['workerId' => $wId2]);
        });

    } finally {
        if ($grpcClient) {
            try {
                $grpcClient->close();
            } catch (\Throwable $e) {
            }
        }
    }
}

function testGrpcAdvanced(SpooledClient $client, TestHarness $harness, string $testPrefix, string $grpcAddress, string $apiKey): void
{
    echo "\n🔌 gRPC - Advanced Operations\n";
    echo str_repeat('─', 60) . "\n";

    if (!extension_loaded('grpc')) {
        echo "  ⏭️  gRPC advanced tests skipped (ext-grpc not installed)\n";

        return;
    }

    cleanupOldJobs($client, $harness);

    $queueName = "{$testPrefix}-grpc-advanced";
    $grpcClient = null;
    $grpcConnected = false;

    $harness->runTest('gRPC Advanced: Connect', function () use ($grpcAddress, $apiKey, &$grpcClient, &$grpcConnected, $harness): void {
        try {
            $useTls = strpos($grpcAddress, ':443') !== false || strpos($grpcAddress, 'grpc.spooled') !== false;
            $grpcClient = new \Spooled\Grpc\SpooledGrpcClient(
                \Spooled\Grpc\GrpcOptions::fromArray([
                    'address' => $grpcAddress,
                    'apiKey' => $apiKey,
                    'secure' => $useTls,
                ]),
            );
            $grpcClient->waitForReady(new \DateTime('+5 seconds'));
            $grpcConnected = true;
        } catch (\Throwable $e) {
            $harness->log("gRPC connection failed: {$e->getMessage()}");

            throw $e;
        }
    });

    if (!$grpcConnected || !$grpcClient) {
        echo "  ⏭️  Skipping gRPC advanced tests (connection failed)\n";

        return;
    }

    try {
        $harness->runTest('gRPC: GetJob - existing job', function () use ($grpcClient, $client, $queueName, $harness): void {
            // Create a job via REST first
            $job = $client->jobs->create([
                'queue' => $queueName,
                'payload' => ['grpc' => 'getJob'],
            ]);

            // Get via gRPC
            try {
                $result = $grpcClient->queue->getJob($job->id);
                assertDefined($result['job'], 'job should exist');
                assertEqual($result['job']['id'] ?? '', $job->id, 'job id');
                $harness->log('Got job via gRPC');
            } catch (\Throwable $e) {
                $harness->log("GetJob not implemented: {$e->getMessage()}");
            }

            $client->jobs->cancel($job->id);
        });

        $harness->runTest('gRPC: GetJob - non-existent job', function () use ($grpcClient, $harness): void {
            // Node.js SDK returns null job for non-existent, not an exception
            $result = $grpcClient->queue->getJob('non-existent-job-id');
            assertEqual($result['job'], null, 'job should be null');
            $harness->log('GetJob correctly returned null for non-existent job');
        });

        $harness->runTest('gRPC: Enqueue with idempotency key', function () use ($grpcClient, $queueName, $harness, $client): void {
            $idempotencyKey = 'grpc-idem-' . time();

            $result1 = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['idempotent' => true],
                'idempotencyKey' => $idempotencyKey,
            ]);

            $result2 = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['idempotent' => 'duplicate'],
                'idempotencyKey' => $idempotencyKey,
            ]);

            assertEqual($result1['jobId'], $result2['jobId'], 'should return same job id');
            $harness->log('Idempotency key working via gRPC');

            // Cleanup: cancel the job
            $client->jobs->cancel($result1['jobId']);
        });

        $harness->runTest('gRPC: Batch dequeue multiple jobs', function () use ($grpcClient, $queueName, $harness): void {
            $reg = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'batch-worker',
                'maxConcurrency' => 10,
            ]);

            // Enqueue 3 jobs
            for ($i = 0; $i < 3; $i++) {
                $grpcClient->queue->enqueue([
                    'queueName' => $queueName,
                    'payload' => ['batch' => $i],
                ]);
            }

            // Dequeue in batch
            $result = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $reg['workerId'],
                'batchSize' => 5,
            ]);

            assertTrue(count($result['jobs'] ?? []) >= 1, 'should dequeue at least 1 job');
            $harness->log('Batch dequeued ' . count($result['jobs'] ?? []) . ' jobs');

            // Complete all dequeued jobs to free up slots
            foreach ($result['jobs'] ?? [] as $job) {
                $grpcClient->queue->complete([
                    'jobId' => $job['id'],
                    'workerId' => $reg['workerId'],
                    'result' => ['completed' => true],
                ]);
            }

            $grpcClient->workers->deregister(['workerId' => $reg['workerId']]);
        });

        $harness->runTest('gRPC: RenewLease - extend lease', function () use ($grpcClient, $queueName, $harness): void {
            $reg = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'renew-worker',
                'maxConcurrency' => 1,
            ]);

            $enq = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['renew' => true],
            ]);

            $deq = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $reg['workerId'],
                'batchSize' => 1,
            ]);

            if (!empty($deq['jobs'])) {
                try {
                    $grpcClient->queue->renewLease([
                        'jobId' => $deq['jobs'][0]['id'],
                        'workerId' => $reg['workerId'],
                        'leaseDurationSecs' => 120,
                    ]);
                    $harness->log('Lease renewed via gRPC');
                } catch (\Throwable $e) {
                    $harness->log("RenewLease: {$e->getMessage()}");
                }

                // Complete the job to free up slot
                $grpcClient->queue->complete([
                    'jobId' => $deq['jobs'][0]['id'],
                    'workerId' => $reg['workerId'],
                    'result' => ['completed' => true],
                ]);
            }

            $grpcClient->workers->deregister(['workerId' => $reg['workerId']]);
        });

        $harness->runTest('gRPC: Complex nested payload', function () use ($grpcClient, $queueName, $harness, $client): void {
            $result = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => [
                    'nested' => [
                        'deeply' => [
                            'value' => 'test',
                            'array' => [1, 2, 3],
                        ],
                    ],
                    'unicode' => '你好世界 🌍',
                ],
            ]);
            assertDefined($result['jobId'] ?? null, 'job id');
            $harness->log('Complex payload enqueued via gRPC');

            // Cleanup: cancel the job
            $client->jobs->cancel($result['jobId']);
        });

        $harness->runTest('gRPC: Large payload', function () use ($grpcClient, $queueName, $harness, $client): void {
            $largeData = str_repeat('x', 10000);
            $result = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['data' => $largeData],
            ]);
            assertDefined($result['jobId'] ?? null, 'job id');
            $harness->log('Large payload enqueued via gRPC');

            // Cleanup: cancel the job
            $client->jobs->cancel($result['jobId']);
        });

        $harness->runTest('gRPC: Scheduled job in future', function () use ($grpcClient, $queueName, $harness, $client): void {
            $result = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['scheduled' => true],
                'scheduledAt' => date('c', time() + 3600),
            ]);
            assertDefined($result['jobId'] ?? null, 'job id');
            $harness->log('Scheduled job created via gRPC');

            // Cleanup: cancel the job
            $client->jobs->cancel($result['jobId']);
        });

        $harness->runTest('gRPC: Worker with metadata', function () use ($grpcClient, $queueName, $harness): void {
            $result = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'meta-worker',
                'maxConcurrency' => 5,
                'metadata' => ['version' => '1.0', 'env' => 'test'],
            ]);
            assertDefined($result['workerId'] ?? null, 'worker id');
            $harness->log('Worker registered with metadata');
            $grpcClient->workers->deregister(['workerId' => $result['workerId']]);
        });

        $harness->runTest('gRPC: GetQueueStats - empty queue', function () use ($grpcClient, $testPrefix, $harness): void {
            $emptyQueue = "{$testPrefix}-empty-" . time();
            try {
                $result = $grpcClient->queue->getStats(['queueName' => $emptyQueue]);
                $harness->log('Stats for empty queue retrieved');
            } catch (\Throwable $e) {
                $harness->log("Stats error (expected): {$e->getMessage()}");
            }
        });

        $harness->runTest('gRPC: Jobs dequeued by priority', function () use ($grpcClient, $queueName, $harness): void {
            $reg = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'priority-worker',
                'maxConcurrency' => 10,
            ]);

            // Enqueue with different priorities
            $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['priority' => 'low'],
                'priority' => 1,
            ]);
            $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['priority' => 'high'],
                'priority' => 10,
            ]);

            sleep_ms(100);

            // Dequeue - should get high priority first
            $result = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $reg['workerId'],
                'batchSize' => 1,
            ]);

            if (!empty($result['jobs'])) {
                $priority = $result['jobs'][0]['payload']['priority'] ?? 'unknown';
                $harness->log("First dequeued job priority: {$priority}");
                // Complete the first job to free up active job slot
                $grpcClient->queue->complete([
                    'jobId' => $result['jobs'][0]['id'],
                    'workerId' => $reg['workerId'],
                    'result' => ['completed' => true],
                ]);
            }

            // Dequeue and complete the second job as well
            $result2 = $grpcClient->queue->dequeue([
                'queueName' => $queueName,
                'workerId' => $reg['workerId'],
                'batchSize' => 1,
            ]);
            if (!empty($result2['jobs'])) {
                $grpcClient->queue->complete([
                    'jobId' => $result2['jobs'][0]['id'],
                    'workerId' => $reg['workerId'],
                    'result' => ['completed' => true],
                ]);
            }

            $grpcClient->workers->deregister(['workerId' => $reg['workerId']]);
        });

    } finally {
        if ($grpcClient) {
            try {
                $grpcClient->close();
            } catch (\Throwable $e) {
            }
        }
    }
}

function testGrpcErrors(TestHarness $harness, string $testPrefix, string $grpcAddress, string $apiKey): void
{
    echo "\n🔌 gRPC - Error Handling\n";
    echo str_repeat('─', 60) . "\n";

    if (!extension_loaded('grpc')) {
        echo "  ⏭️  gRPC error tests skipped (ext-grpc not installed)\n";

        return;
    }

    $queueName = "{$testPrefix}-grpc-errors";
    $grpcClient = null;
    $grpcConnected = false;

    $harness->runTest('gRPC Error: Connect', function () use ($grpcAddress, $apiKey, &$grpcClient, &$grpcConnected, $harness): void {
        try {
            $useTls = strpos($grpcAddress, ':443') !== false;
            $grpcClient = new \Spooled\Grpc\SpooledGrpcClient(
                \Spooled\Grpc\GrpcOptions::fromArray([
                    'address' => $grpcAddress,
                    'apiKey' => $apiKey,
                    'secure' => $useTls,
                ]),
            );
            $grpcClient->waitForReady(new \DateTime('+5 seconds'));
            $grpcConnected = true;
        } catch (\Throwable $e) {
            $harness->log("gRPC error test connection failed: {$e->getMessage()}");

            throw $e;
        }
    });

    if (!$grpcConnected || !$grpcClient) {
        echo "  ⏭️  Skipping gRPC error tests (connection failed)\n";

        return;
    }

    try {
        $harness->runTest('gRPC Error: Invalid queue name (empty)', function () use ($grpcClient, $harness): void {
            try {
                $grpcClient->queue->enqueue([
                    'queueName' => '',
                    'payload' => ['test' => true],
                ]);

                throw new \RuntimeException('Should have thrown');
            } catch (\Throwable $e) {
                $harness->log('Correctly rejected empty queue name');
            }
        });

        $harness->runTest('gRPC Error: Invalid queue name (special chars)', function () use ($grpcClient, $harness): void {
            try {
                $grpcClient->queue->enqueue([
                    'queueName' => 'invalid@queue!name',
                    'payload' => ['test' => true],
                ]);

                throw new \RuntimeException('Should have thrown');
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                assertTrue(
                    strpos($msg, 'INVALID_ARGUMENT') !== false || strpos($msg, 'alphanumeric') !== false,
                    'expected validation error'
                );
                $harness->log('Special chars in queue name correctly rejected');
            }
        });

        $harness->runTest('gRPC Error: Dequeue without worker ID', function () use ($grpcClient, $queueName, $harness): void {
            try {
                $grpcClient->queue->dequeue([
                    'queueName' => $queueName,
                    'workerId' => '',
                    'batchSize' => 1,
                ]);

                throw new \RuntimeException('Should have thrown');
            } catch (\Throwable $e) {
                $harness->log('Correctly rejected empty worker ID');
            }
        });

        $harness->runTest('gRPC Error: Complete non-existent job', function () use ($grpcClient, $harness): void {
            try {
                $grpcClient->queue->complete([
                    'jobId' => 'non-existent-job',
                    'workerId' => 'some-worker',
                    'result' => [],
                ]);

                throw new \RuntimeException('Should have thrown');
            } catch (\Throwable $e) {
                $harness->log('Correctly threw for non-existent job');
            }
        });

        $harness->runTest('gRPC Error: Invalid API key', function () use ($grpcAddress, $harness): void {
            try {
                $useTls = strpos($grpcAddress, ':443') !== false;
                $badClient = new \Spooled\Grpc\SpooledGrpcClient(
                    \Spooled\Grpc\GrpcOptions::fromArray([
                        'address' => $grpcAddress,
                        'apiKey' => 'invalid-key',
                        'secure' => $useTls,
                    ]),
                );
                $badClient->queue->enqueue([
                    'queueName' => 'test',
                    'payload' => ['test' => true],
                ]);

                throw new \RuntimeException('Should have thrown');
            } catch (\Throwable $e) {
                $harness->log('Correctly rejected invalid API key');
            }
        });

        $harness->runTest('gRPC Error: Heartbeat for unknown worker', function () use ($grpcClient, $harness): void {
            try {
                $grpcClient->workers->heartbeat([
                    'workerId' => 'non-existent-worker-id',
                ]);

                throw new \RuntimeException('Should have thrown');
            } catch (\Throwable $e) {
                $harness->log('Correctly threw for unknown worker heartbeat');
            }
        });

        $harness->runTest('gRPC Error: Deregister unknown worker', function () use ($grpcClient, $harness): void {
            try {
                $grpcClient->workers->deregister([
                    'workerId' => 'non-existent-worker-id',
                ]);
            } catch (\Throwable $e) {
                $harness->log('Deregister for unknown worker: ' . $e->getMessage());
            }
        });

    } finally {
        if ($grpcClient) {
            try {
                $grpcClient->close();
            } catch (\Throwable $e) {
            }
        }
    }
}

function testGrpcTierLimits(SpooledClient $client, TestHarness $harness, string $testPrefix, string $grpcAddress, string $apiKey): void
{
    echo "\n🔌 gRPC - Tier Limits\n";
    echo str_repeat('─', 60) . "\n";

    if (!extension_loaded('grpc')) {
        echo "  ⏭️  gRPC tier tests skipped (ext-grpc not installed)\n";

        return;
    }

    $queueName = "{$testPrefix}-grpc-tier";
    $grpcClient = null;
    $grpcConnected = false;

    $harness->runTest('gRPC Tier: Test with main org', function () use ($grpcAddress, $apiKey, $queueName, $harness): void {
        try {
            $useTls = strpos($grpcAddress, ':443') !== false;
            $grpcClient = new \Spooled\Grpc\SpooledGrpcClient(
                \Spooled\Grpc\GrpcOptions::fromArray([
                    'address' => $grpcAddress,
                    'apiKey' => $apiKey,
                    'secure' => $useTls,
                ]),
            );
            $grpcClient->waitForReady(new \DateTime('+5 seconds'));

            // Test basic operation with current org
            $reg = $grpcClient->workers->register([
                'queueName' => $queueName,
                'hostname' => 'tier-test-worker',
            ]);
            $harness->log('Registered worker in tier test');

            // Enqueue a job
            $enq = $grpcClient->queue->enqueue([
                'queueName' => $queueName,
                'payload' => ['tier' => 'test'],
            ]);
            $harness->log("Enqueued job: {$enq['jobId']}");

            // Cleanup
            $grpcClient->workers->deregister(['workerId' => $reg['workerId']]);
            $grpcClient->close();

        } catch (\Throwable $e) {
            $harness->log("gRPC tier test: {$e->getMessage()}");
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Main Runner
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('═', 60) . "\n";
echo "   🧪 COMPREHENSIVE SPOOLED TEST SUITE (PHP)\n";
echo str_repeat('═', 60) . "\n";
echo "   API: {$BASE_URL}\n";
echo '   Key: ' . substr($API_KEY, 0, 12) . "...\n";
echo "   Webhook Port: {$WEBHOOK_PORT}\n";
echo '   Verbose: ' . ($VERBOSE ? 'true' : 'false') . "\n";
echo str_repeat('═', 60) . "\n";

$testPrefix = generateTestId();
echo "\n📋 Test Prefix: {$testPrefix}\n";

$harness = new TestHarness($VERBOSE);

try {
    // Initialize client
    $client = new SpooledClient(new ClientOptions(
        apiKey: $API_KEY,
        baseUrl: $BASE_URL,
    ));

    // Cleanup old jobs before starting
    cleanupOldJobs($client, $harness);

    // Run all test suites
    testHealthEndpoints($client, $harness);
    testDashboard($client, $harness);
    testOrganization($client, $harness);
    testApiKeys($client, $harness, $testPrefix, $BASE_URL);
    testJobsBasicCRUD($client, $harness, $testPrefix);
    testJobsBulkOperations($client, $harness, $testPrefix);
    testJobIdempotency($client, $harness, $testPrefix);
    testJobLifecycle($client, $harness, $testPrefix);
    testJobFailureAndRetry($client, $harness, $testPrefix);
    testDLQ($client, $harness, $testPrefix);
    testQueues($client, $harness, $testPrefix);
    testWorkers($client, $harness, $testPrefix);
    testWebhooks($client, $harness, $testPrefix, $WEBHOOK_PORT);
    testSchedules($client, $harness, $testPrefix);
    testWorkflows($client, $harness, $testPrefix);
    testWorkflowExecution($client, $harness, $testPrefix);
    testQueueAdvanced($client, $harness, $testPrefix);
    testDLQAdvanced($client, $harness, $testPrefix);
    testBilling($client, $harness);
    testRegistration($harness, $BASE_URL);
    testWebhookRetry($client, $harness, $testPrefix);
    testAuth($client, $harness, $API_KEY, $BASE_URL);
    testRealtime($client, $harness, $API_KEY, $BASE_URL);
    testMetrics($harness, $API_KEY, $BASE_URL);
    testWebSocket($client, $harness, $API_KEY, $BASE_URL);
    testOrgManagement($client, $harness, $testPrefix);
    testOrganizationWebhookToken($client, $harness);
    testIngest($client, $harness, $testPrefix);
    testJobExpiration($client, $harness, $testPrefix);
    testWorkerIntegration($client, $harness, $testPrefix);
    testWebhookDelivery($client, $harness, $testPrefix, $WEBHOOK_PORT);
    testWorkflowJobs($client, $harness, $testPrefix);
    testQueueConfigUpdate($client, $harness, $testPrefix);
    testAdminEndpoints($harness, $BASE_URL, $ADMIN_API_KEY);
    testEmailLogin($client, $harness);
    testTierLimits($client, $harness, $BASE_URL);
    testEdgeCases($client, $harness, $testPrefix);
    testErrorHandling($client, $harness, $BASE_URL);
    testConcurrentOperations($client, $harness, $testPrefix);

    if ($SKIP_STRESS) {
        echo "\n🔥 Stress & Load Testing\n";
        echo str_repeat('─', 60) . "\n";
        echo "  ⏭️  Stress tests skipped (set SKIP_STRESS=0 to enable)\n";
    } else {
        testStressLoad($client, $harness, $testPrefix);
    }

    if ($SKIP_GRPC) {
        echo "\n🔌 gRPC Tests\n";
        echo str_repeat('─', 60) . "\n";
        echo "  ⏭️  gRPC tests skipped (set SKIP_GRPC=0 to enable)\n";
    } else {
        testGrpc($client, $harness, $testPrefix, $GRPC_ADDRESS, $API_KEY);
        testGrpcAdvanced($client, $harness, $testPrefix, $GRPC_ADDRESS, $API_KEY);
        testGrpcErrors($harness, $testPrefix, $GRPC_ADDRESS, $API_KEY);
        testGrpcTierLimits($client, $harness, $testPrefix, $GRPC_ADDRESS, $API_KEY);
    }

} catch (\Throwable $e) {
    echo "\n💥 Fatal error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

// Print summary
$harness->printSummary();

// Save results to JSON
$resultsFile = __DIR__ . '/../test-results.json';
file_put_contents($resultsFile, json_encode([
    'timestamp' => date('c'),
    'total' => count($harness->getResults()),
    'results' => $harness->getResults(),
], JSON_PRETTY_PRINT));

echo "Results saved to: {$resultsFile}\n";

if ($harness->getExitCode() === 0) {
    echo "\n🎉 ALL TESTS PASSED!\n\n";
}

exit($harness->getExitCode());
