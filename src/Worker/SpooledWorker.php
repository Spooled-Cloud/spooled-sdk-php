<?php

declare(strict_types=1);

namespace Spooled\Worker;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Spooled\Config\CircuitBreakerConfig;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;
use Spooled\Errors\ConflictError;
use Spooled\Errors\NetworkError;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\ServerError;
use Spooled\Errors\TimeoutError;
use Spooled\Resources\JobsResource;
use Spooled\SpooledClient;
use Spooled\Types\ClaimedJob;
use Spooled\Version;
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
    private const RENEWAL_SAFETY_FRACTION = 0.1;

    private const MIN_RENEWAL_SAFETY_SECONDS = 0.05;

    private const MAX_RENEWAL_SAFETY_SECONDS = 1.0;

    private const CHILD_STOP_GRACE_SECONDS = 0.25;

    private const CHILD_WAIT_POLL_MICROS = 10_000;

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

    private ?int $leaseRenewalPid = null;

    /** @var array<int, true> */
    private array $unreapedRenewalPids = [];

    /** @var callable|int|null */
    private mixed $previousLeaseLossHandler = null;

    private ?bool $previousAsyncSignals = null;

    private bool $renewalChildCleanExit = false;

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

        $this->assertLeaseRenewalSupport();
        $this->state = WorkerState::STARTING;
        $this->debug("Starting worker for queue: {$this->config->queueName}");

        try {
            // Register with the API
            $registration = $this->client->workers->register([
                'queueName' => $this->config->queueName,
                'hostname' => $this->config->hostname ?? gethostname() ?: 'php-worker',
                'maxConcurrency' => $this->config->concurrency,
                'workerType' => $this->config->workerType,
                'version' => $this->config->version ?? Version::VERSION,
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

        $handlerStarted = false;

        try {
            $this->startLeaseRenewal($activeJob);
            $handlerStarted = true;

            try {
                $result = ($this->handler)($context);
            } finally {
                // Renewal must be fully cancelled and reaped before settlement so
                // it can never race complete/fail or renew a replacement lease.
                $this->stopLeaseRenewal();
            }

            if ($activeJob->cancelled) {
                $this->debug("Job {$job->id} was cancelled");

                return;
            }

            $this->completeJob($job, $result);
        } catch (LeaseLostException $e) {
            $activeJob->cancelled = true;
            $this->reportWorkerError($e, $job, 'renew');
        } catch (Throwable $e) {
            if (!$handlerStarted) {
                $this->reportWorkerError($e, $job, 'renew');

                return;
            }

            if ($activeJob->cancelled) {
                $this->debug("Job {$job->id} was cancelled");

                return;
            }

            $this->failJob($job, $e->getMessage());
        } finally {
            $this->stopLeaseRenewal();
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

            $params = [
                'workerId' => $this->workerId,
                'result' => $resultArray,
            ];

            // Echo the lease fencing token from claim so completion applies
            // only to the lease this worker actually holds.
            if ($job->leaseId !== null) {
                $params['leaseId'] = $job->leaseId;
            }

            $response = $this->client->jobs->complete($job->id, $params);
            if (($response['success'] ?? true) !== true) {
                throw new RuntimeException("Completion rejected for job {$job->id}");
            }

            $this->completedJobs++;
            $this->emit(WorkerEvent::JOB_COMPLETED->value, [
                'jobId' => $job->id,
                'queueName' => $job->queueName,
                'result' => $resultArray,
            ]);

            $this->debug("Job completed: {$job->id}");

        } catch (Throwable $e) {
            $this->reportWorkerError($e, $job, 'complete');
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
            $params = [
                'workerId' => $this->workerId,
                'error' => $error,
            ];

            // Echo the lease fencing token from claim so the failure applies
            // only to the lease this worker actually holds.
            if ($job->leaseId !== null) {
                $params['leaseId'] = $job->leaseId;
            }

            $response = $this->client->jobs->fail($job->id, $params);
            if (($response['success'] ?? true) !== true) {
                throw new RuntimeException("Failure settlement rejected for job {$job->id}");
            }

            $this->failedJobs++;
            $this->emit(WorkerEvent::JOB_FAILED->value, [
                'jobId' => $job->id,
                'queueName' => $job->queueName,
                'error' => $error,
                'willRetry' => $willRetry,
            ]);

            $this->debug("Job failed: {$job->id} - {$error}");

        } catch (Throwable $e) {
            $this->reportWorkerError($e, $job, 'fail');
        }
    }

    private function startLeaseRenewal(ActiveJob $activeJob): void
    {
        if (!$this->workerId) {
            return;
        }

        $this->reapRenewalChildren();
        $this->assertLeaseRenewalSupport();

        $parentPid = getmypid();
        if ($parentPid === false) {
            throw new RuntimeException('Unable to determine worker process ID for lease renewal.');
        }

        $this->previousLeaseLossHandler = pcntl_signal_get_handler(SIGUSR1);
        $this->previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, function () use ($activeJob): void {
            $activeJob->cancelled = true;

            throw new LeaseLostException("Lease lost while processing job {$activeJob->job->id}");
        });

        $renewalOptions = $this->client->getOptions();
        $controlSignals = [SIGTERM, SIGINT, SIGHUP, SIGQUIT, SIGUSR1];
        $previousSignalMask = [];
        if (!pcntl_sigprocmask(SIG_BLOCK, $controlSignals, $previousSignalMask)) {
            $this->restoreLeaseLossHandler();

            throw new RuntimeException('Unable to block signals while starting lease renewal process.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            pcntl_sigprocmask(SIG_SETMASK, $previousSignalMask);
            $this->restoreLeaseLossHandler();

            throw new RuntimeException('Unable to start lease renewal process.');
        }

        if ($pid === 0) {
            $this->configureRenewalChild($parentPid, $previousSignalMask);
            $this->runLeaseRenewalLoop($activeJob->job, $parentPid, $renewalOptions);
        }

        pcntl_sigprocmask(SIG_SETMASK, $previousSignalMask);
        $this->leaseRenewalPid = $pid;
    }

    private function assertLeaseRenewalSupport(): void
    {
        if (!function_exists('pcntl_fork')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_sigprocmask')
            || !function_exists('posix_kill')) {
            throw new RuntimeException(
                'SpooledWorker automatic lease renewal requires the pcntl and posix extensions.',
            );
        }
    }

    /** @param array<int> $previousSignalMask */
    private function configureRenewalChild(int $parentPid, array $previousSignalMask = []): void
    {
        $this->renewalChildCleanExit = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_signal(SIGCHLD, SIG_DFL);
        foreach ([SIGTERM, SIGINT, SIGHUP, SIGQUIT] as $signal) {
            pcntl_signal($signal, fn (): never => $this->terminateRenewalChild());
        }
        pcntl_sigprocmask(SIG_SETMASK, $previousSignalMask);

        register_shutdown_function(function () use ($parentPid): void {
            if (!$this->renewalChildCleanExit && $this->isOriginalParentAlive($parentPid)) {
                posix_kill($parentPid, SIGUSR1);
            }
        });
    }

    private function runLeaseRenewalLoop(ClaimedJob $job, int $parentPid, ClientOptions $baseOptions): void
    {
        $intervalMicros = max(
            1,
            (int) round($this->config->leaseDuration * $this->config->heartbeatFraction * 1_000_000),
        );
        $leaseDeadline = microtime(true) + $this->config->leaseDuration;
        $nextDelayMicros = $intervalMicros;

        while (true) {
            usleep($nextDelayMicros);
            $this->exitIfOrphaned($parentPid);

            try {
                $remainingSeconds = $leaseDeadline - microtime(true);
                $renewalJobs = $this->createRenewalJobsResource(
                    $this->buildRenewalClientOptions($baseOptions, $remainingSeconds),
                );
                $params = [
                    'workerId' => $this->workerId,
                    'leaseDurationSecs' => $this->config->leaseDuration,
                ];
                if ($job->leaseId !== null) {
                    $params['leaseId'] = $job->leaseId;
                }

                $response = $renewalJobs->heartbeat($job->id, $params);
                $this->exitIfOrphaned($parentPid);
                if (($response['success'] ?? true) !== true) {
                    $this->signalLeaseLoss($parentPid);
                }

                $leaseDeadline = microtime(true) + $this->config->leaseDuration;
                $nextDelayMicros = $intervalMicros;
            } catch (ConflictError) {
                $this->signalLeaseLoss($parentPid);
            } catch (NetworkError|RateLimitError|ServerError|TimeoutError) {
                $remainingMicros = (int) (($leaseDeadline - microtime(true)) * 1_000_000);
                if ($remainingMicros <= $this->renewalSafetyMarginMicros()) {
                    $this->signalLeaseLoss($parentPid);
                }

                $nextDelayMicros = max(1, min(1_000_000, $remainingMicros - $this->renewalSafetyMarginMicros()));
            } catch (Throwable) {
                $this->signalLeaseLoss($parentPid);
            }
        }
    }

    protected function createRenewalJobsResource(ClientOptions $options): JobsResource
    {
        return (new SpooledClient($options))->jobs;
    }

    private function buildRenewalClientOptions(ClientOptions $baseOptions, float $remainingSeconds): ClientOptions
    {
        $requestBudget = $remainingSeconds - $this->renewalSafetyMarginSeconds();
        if ($requestBudget <= 0) {
            throw new TimeoutError('Insufficient lease time remains for a safe renewal request.');
        }

        $requestTimeout = min($baseOptions->requestTimeout, $requestBudget);

        return $baseOptions->with([
            'connectTimeout' => min($baseOptions->connectTimeout, $requestTimeout),
            'requestTimeout' => $requestTimeout,
            'retry' => RetryConfig::disabled(),
            'circuitBreaker' => CircuitBreakerConfig::disabled(),
            'logger' => new NullLogger(),
        ]);
    }

    private function renewalSafetyMarginSeconds(): float
    {
        return min(
            self::MAX_RENEWAL_SAFETY_SECONDS,
            max(self::MIN_RENEWAL_SAFETY_SECONDS, $this->config->leaseDuration * self::RENEWAL_SAFETY_FRACTION),
        );
    }

    private function renewalSafetyMarginMicros(): int
    {
        return (int) round($this->renewalSafetyMarginSeconds() * 1_000_000);
    }

    private function exitIfOrphaned(int $parentPid): void
    {
        if (!$this->isOriginalParentAlive($parentPid)) {
            $this->terminateRenewalChild();
        }
    }

    private function isOriginalParentAlive(int $parentPid): bool
    {
        return posix_getppid() === $parentPid && posix_kill($parentPid, 0);
    }

    private function signalLeaseLoss(int $parentPid): never
    {
        if ($this->isOriginalParentAlive($parentPid)) {
            posix_kill($parentPid, SIGUSR1);
        }

        $this->terminateRenewalChild();
    }

    private function terminateRenewalChild(): never
    {
        $this->renewalChildCleanExit = true;
        if (!posix_kill(posix_getpid(), SIGKILL)) {
            $this->renewalChildCleanExit = false;

            throw new RuntimeException('Unable to terminate lease renewal child.');
        }

        throw new RuntimeException('Lease renewal child remained alive after SIGKILL.');
    }

    private function stopLeaseRenewal(): void
    {
        $pid = $this->leaseRenewalPid;
        $this->leaseRenewalPid = null;

        if ($pid !== null) {
            $result = $this->waitForRenewalChildOnce($pid);
            if ($result === 0) {
                posix_kill($pid, SIGTERM);
                $result = $this->waitForRenewalChild($pid, self::CHILD_STOP_GRACE_SECONDS);
            }
            if ($result === 0) {
                posix_kill($pid, SIGKILL);
                $result = $this->waitForRenewalChild($pid, self::CHILD_STOP_GRACE_SECONDS);
            }
            if ($result === 0) {
                $this->unreapedRenewalPids[$pid] = true;
                $this->logger->warning("[SpooledWorker] Unable to reap lease renewal child {$pid}");
            }
        }

        $this->reapRenewalChildren();
        $this->restoreLeaseLossHandler();
    }

    private function reapRenewalChildren(): void
    {
        foreach (array_keys($this->unreapedRenewalPids) as $pid) {
            $result = $this->waitForRenewalChildOnce($pid);
            if ($result !== 0) {
                unset($this->unreapedRenewalPids[$pid]);
            }
        }
    }

    private function waitForRenewalChild(int $pid, float $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $result = $this->waitForRenewalChildOnce($pid);
            if ($result !== 0) {
                return $result;
            }
            usleep(self::CHILD_WAIT_POLL_MICROS);
        } while (microtime(true) < $deadline);

        return 0;
    }

    private function waitForRenewalChildOnce(int $pid): int
    {
        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
        } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);

        return $result;
    }

    private function restoreLeaseLossHandler(): void
    {
        if ($this->previousLeaseLossHandler !== null && function_exists('pcntl_signal')) {
            pcntl_signal(SIGUSR1, $this->previousLeaseLossHandler);
            $this->previousLeaseLossHandler = null;
        }
        if ($this->previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
            pcntl_async_signals($this->previousAsyncSignals);
            $this->previousAsyncSignals = null;
        }
    }

    private function reportWorkerError(Throwable $error, ClaimedJob $job, string $operation): void
    {
        $this->logger->error("[SpooledWorker] Failed to {$operation} job {$job->id}: {$error->getMessage()}", [
            'operation' => $operation,
            'jobId' => $job->id,
            'queueName' => $job->queueName,
            'leaseId' => $job->leaseId,
            'exception' => $error,
        ]);
        $this->emit(WorkerEvent::ERROR->value, [
            'error' => $error,
            'operation' => $operation,
            'jobId' => $job->id,
            'queueName' => $job->queueName,
            'leaseId' => $job->leaseId,
        ]);
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
        $this->stopLeaseRenewal();
        $this->reapRenewalChildren();

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
