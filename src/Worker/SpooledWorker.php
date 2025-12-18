<?php

declare(strict_types=1);

namespace Spooled\Worker;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Spooled\SpooledClient;
use Spooled\Types\ClaimedJob;
use Throwable;

/**
 * Worker state constants.
 */
enum WorkerState: string
{
    case IDLE = 'idle';
    case STARTING = 'starting';
    case RUNNING = 'running';
    case STOPPING = 'stopping';
    case STOPPED = 'stopped';
    case ERROR = 'error';
}

/**
 * Worker event types.
 */
enum WorkerEvent: string
{
    case STARTED = 'started';
    case STOPPED = 'stopped';
    case ERROR = 'error';
    case JOB_CLAIMED = 'job:claimed';
    case JOB_STARTED = 'job:started';
    case JOB_COMPLETED = 'job:completed';
    case JOB_FAILED = 'job:failed';
}

/**
 * Active job tracking.
 */
final class ActiveJob
{
    public function __construct(
        public readonly ClaimedJob $job,
        public readonly DateTimeImmutable $startedAt,
        public bool $cancelled = false,
    ) {
    }
}

/**
 * Spooled Worker - processes jobs from a queue.
 *
 * Matches the Node.js SDK SpooledWorker API pattern.
 *
 * @example
 * ```php
 * $worker = new SpooledWorker($client, [
 *     'queueName' => 'my-queue',
 *     'concurrency' => 10,
 * ]);
 *
 * $worker->process(function (JobContext $ctx) {
 *     // Process job
 *     return ['success' => true];
 * });
 *
 * $worker->on('job:completed', function ($data) {
 *     echo "Job completed: {$data['jobId']}\n";
 * });
 *
 * $worker->start();
 *
 * // Later: graceful shutdown
 * $worker->stop();
 * ```
 */
class SpooledWorker
{
    private readonly SpooledClient $client;

    private readonly WorkerConfig $config;

    private readonly LoggerInterface $logger;

    private WorkerState $state = WorkerState::IDLE;

    private ?string $workerId = null;

    /** @var callable|null */
    private $handler = null;

    /** @var array<string, ActiveJob> */
    private array $activeJobs = [];

    private int $completedJobs = 0;

    private int $failedJobs = 0;

    private ?float $lastWorkerHeartbeat = null;

    /** @var array<string, array<callable>> */
    private array $eventHandlers = [];

    public function __construct(
        SpooledClient $client,
        WorkerConfig|array $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $client;
        $this->config = $options instanceof WorkerConfig
            ? $options
            : WorkerConfig::fromArray($options);
        $clientLogger = $client->getLogger();
        $this->logger = $logger ?? $clientLogger ?? new NullLogger();
    }

    /**
     * Get current worker state.
     */
    public function getState(): string
    {
        return $this->state->value;
    }

    /**
     * Check if worker is shutting down.
     */
    public function isShuttingDown(): bool
    {
        return $this->state === WorkerState::STOPPING || $this->state === WorkerState::STOPPED;
    }

    /**
     * Get worker ID (available after start).
     */
    public function getWorkerId(): ?string
    {
        return $this->workerId;
    }

    /**
     * Get number of active jobs.
     */
    public function getActiveJobCount(): int
    {
        return count($this->activeJobs);
    }

    /**
     * Get completed jobs count.
     */
    public function getCompletedJobs(): int
    {
        return $this->completedJobs;
    }

    /**
     * Get failed jobs count.
     */
    public function getFailedJobs(): int
    {
        return $this->failedJobs;
    }

    /**
     * Register job handler.
     *
     * Must be called before start().
     *
     * @param callable(JobContext): mixed $handler
     */
    public function process(callable $handler): void
    {
        if ($this->state !== WorkerState::IDLE) {
            throw new RuntimeException('Cannot set handler after worker has started');
        }
        $this->handler = $handler;
    }

    /**
     * Add event listener.
     *
     * @param callable(array<string, mixed>): void $handler
     * @return callable Unsubscribe function
     */
    public function on(string $event, callable $handler): callable
    {
        if (!isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event] = [];
        }
        $this->eventHandlers[$event][] = $handler;

        // Return unsubscribe function
        return fn () => $this->off($event, $handler);
    }

    /**
     * Remove event listener.
     */
    public function off(string $event, callable $handler): void
    {
        if (isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event] = array_filter(
                $this->eventHandlers[$event],
                fn ($h) => $h !== $handler,
            );
        }
    }

    /**
     * Start the worker.
     *
     * This method blocks until stop() is called.
     */
    public function start(): void
    {
        if ($this->state !== WorkerState::IDLE) {
            throw new RuntimeException("Cannot start worker in state: {$this->state->value}");
        }

        if ($this->handler === null) {
            throw new RuntimeException('No job handler registered. Call process() first.');
        }

        $this->state = WorkerState::STARTING;
        $this->debug("Starting worker for queue: {$this->config->queueName}");

        try {
            // Register with the API
            $registration = $this->client->workers->register([
                'queues' => [$this->config->queueName],
                'name' => $this->config->hostname ?? gethostname() ?: 'php-worker',
                'concurrency' => $this->config->concurrency,
                'workerType' => 'php',
                'version' => $this->config->version ?? '1.0.0',
                'metadata' => $this->config->metadata ?? [],
            ]);

            $this->workerId = $registration->id;
            $this->debug("Worker registered: {$this->workerId}");

            $this->state = WorkerState::RUNNING;
            $this->emit(WorkerEvent::STARTED->value, [
                'workerId' => $this->workerId,
                'queueName' => $this->config->queueName,
            ]);

            // Main poll loop
            $this->runPollLoop();

        } catch (Throwable $e) {
            $this->state = WorkerState::ERROR;
            $this->emit(WorkerEvent::ERROR->value, ['error' => $e]);

            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Stop the worker gracefully.
     *
     * Waits for active jobs to complete (with timeout).
     */
    public function stop(): void
    {
        if ($this->state !== WorkerState::RUNNING) {
            return;
        }

        $this->state = WorkerState::STOPPING;
        $this->debug('Stopping worker...');

        // Cancel active jobs
        foreach ($this->activeJobs as $activeJob) {
            $activeJob->cancelled = true;
        }

        // Wait for active jobs (with timeout)
        $this->waitForActiveJobs();

        $this->state = WorkerState::STOPPED;
    }

    /**
     * Main poll loop.
     */
    private function runPollLoop(): void
    {
        while ($this->state === WorkerState::RUNNING) {
            // Send worker heartbeat if needed
            $this->maybeWorkerHeartbeat();

            // Check capacity
            $availableSlots = $this->config->concurrency - count($this->activeJobs);
            if ($availableSlots <= 0) {
                $this->sleep($this->config->pollInterval);
                continue;
            }

            // Try to claim jobs
            try {
                $result = $this->client->jobs->claim([
                    'queueName' => $this->config->queueName,
                    'workerId' => $this->workerId,
                    'limit' => min($availableSlots, 10),
                    'leaseDurationSecs' => $this->config->leaseDuration,
                ]);

                foreach ($result->jobs as $job) {
                    $this->processJob($job);
                }

                if (count($result->jobs) === 0) {
                    $this->sleep($this->config->pollInterval);
                }
            } catch (Throwable $e) {
                $this->debug("Poll failed: {$e->getMessage()}");
                $this->emit(WorkerEvent::ERROR->value, ['error' => $e]);
                $this->sleep($this->config->pollInterval);
            }
        }
    }

    /**
     * Process a single job.
     */
    private function processJob(ClaimedJob $job): void
    {
        $this->emit(WorkerEvent::JOB_CLAIMED->value, [
            'jobId' => $job->id,
            'queueName' => $job->queueName,
        ]);

        $activeJob = new ActiveJob($job, new DateTimeImmutable());
        $this->activeJobs[$job->id] = $activeJob;

        $this->emit(WorkerEvent::JOB_STARTED->value, [
            'jobId' => $job->id,
            'queueName' => $job->queueName,
        ]);

        $context = new JobContext(
            jobId: $job->id,
            queueName: $job->queueName,
            payload: $job->payload,
            retryCount: $job->retryCount,
            maxRetries: $job->maxRetries,
            worker: $this,
        );

        try {
            // Execute handler
            $result = ($this->handler)($context);

            // Check if cancelled
            if ($activeJob->cancelled) {
                $this->debug("Job {$job->id} was cancelled");

                return;
            }

            // Complete the job
            $this->completeJob($job, $result);

        } catch (Throwable $e) {
            // Check if cancelled
            if ($activeJob->cancelled) {
                $this->debug("Job {$job->id} was cancelled");

                return;
            }

            $this->failJob($job, $e->getMessage());
        } finally {
            unset($this->activeJobs[$job->id]);
        }
    }

    /**
     * Complete a job.
     *
     * @param mixed $result
     */
    private function completeJob(ClaimedJob $job, mixed $result): void
    {
        if (!$this->workerId) {
            return;
        }

        try {
            $resultArray = is_array($result) ? $result : ($result !== null ? ['result' => $result] : null);

            $this->client->jobs->complete($job->id, [
                'workerId' => $this->workerId,
                'result' => $resultArray,
            ]);

            $this->completedJobs++;
            $this->emit(WorkerEvent::JOB_COMPLETED->value, [
                'jobId' => $job->id,
                'queueName' => $job->queueName,
                'result' => $resultArray,
            ]);

            $this->debug("Job completed: {$job->id}");

        } catch (Throwable $e) {
            $this->debug("Failed to complete job {$job->id}: {$e->getMessage()}");
        }
    }

    /**
     * Fail a job.
     */
    private function failJob(ClaimedJob $job, string $error): void
    {
        if (!$this->workerId) {
            return;
        }

        $willRetry = $job->retryCount < $job->maxRetries;

        try {
            $this->client->jobs->fail($job->id, [
                'workerId' => $this->workerId,
                'error' => $error,
            ]);

            $this->failedJobs++;
            $this->emit(WorkerEvent::JOB_FAILED->value, [
                'jobId' => $job->id,
                'queueName' => $job->queueName,
                'error' => $error,
                'willRetry' => $willRetry,
            ]);

            $this->debug("Job failed: {$job->id} - {$error}");

        } catch (Throwable $e) {
            $this->debug("Failed to fail job {$job->id}: {$e->getMessage()}");
        }
    }

    /**
     * Send worker heartbeat if interval elapsed.
     */
    private function maybeWorkerHeartbeat(): void
    {
        if (!$this->workerId) {
            return;
        }

        $now = microtime(true);
        $interval = $this->config->heartbeatInterval / 1000; // Convert ms to seconds

        if ($this->lastWorkerHeartbeat !== null && ($now - $this->lastWorkerHeartbeat) < $interval) {
            return;
        }

        try {
            $this->client->workers->heartbeat($this->workerId, [
                'currentJobs' => count($this->activeJobs),
                'status' => 'healthy',
            ]);
            $this->lastWorkerHeartbeat = $now;
        } catch (Throwable $e) {
            $this->debug("Worker heartbeat failed: {$e->getMessage()}");
        }
    }

    /**
     * Wait for active jobs to complete.
     */
    private function waitForActiveJobs(): void
    {
        if (count($this->activeJobs) === 0) {
            return;
        }

        $this->debug('Waiting for ' . count($this->activeJobs) . ' active jobs to complete...');

        $deadline = microtime(true) + ($this->config->shutdownTimeout / 1000);

        while (count($this->activeJobs) > 0 && microtime(true) < $deadline) {
            $this->sleep(100);
        }

        if (count($this->activeJobs) > 0) {
            $this->logger->warning('Shutdown timeout reached with ' . count($this->activeJobs) . ' active jobs');
        }
    }

    /**
     * Cleanup on shutdown.
     */
    private function cleanup(): void
    {
        if ($this->workerId !== null) {
            try {
                $this->client->workers->deregister($this->workerId);
                $this->debug('Worker deregistered');
            } catch (Throwable $e) {
                $this->debug("Failed to deregister worker: {$e->getMessage()}");
            }
        }

        $this->emit(WorkerEvent::STOPPED->value, [
            'workerId' => $this->workerId ?? '',
            'reason' => 'graceful',
            'completedJobs' => $this->completedJobs,
            'failedJobs' => $this->failedJobs,
        ]);

        $this->workerId = null;
    }

    /**
     * Emit an event.
     *
     * @param array<string, mixed> $data
     */
    private function emit(string $event, array $data): void
    {
        if (!isset($this->eventHandlers[$event])) {
            return;
        }

        foreach ($this->eventHandlers[$event] as $handler) {
            try {
                $handler($data);
            } catch (Throwable $e) {
                $this->debug("Event handler error for {$event}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Debug log.
     */
    private function debug(string $message): void
    {
        $this->logger->debug("[SpooledWorker] {$message}");
    }

    /**
     * Sleep for milliseconds.
     */
    private function sleep(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
