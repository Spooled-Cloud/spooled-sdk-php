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
use Spooled\Resources\WorkersResource;
use Spooled\SpooledClient;
use Spooled\Types\ClaimedJob;
use Spooled\Util\FailureText;
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
    case JOB_TIMEOUT = 'job:timeout';
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
 * Handlers run one at a time in this process. A forked child renews the running
 * job's lease and sends the worker heartbeat while the handler blocks.
 * `stop()` (from a signal handler or anywhere else) only requests a stop: the
 * running job finishes and is acknowledged, then the worker deregisters and
 * `start()` returns. See "Graceful Shutdown" in docs/workers.md.
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

    /**
     * Jobs claimed per poll. Handlers run serially in this process, so claiming
     * more than one only parks the extra jobs' leases until their turn; once the
     * earlier handlers outlast the lease, the later jobs start already expired.
     */
    private const MAX_CLAIM_PER_POLL = 1;

    /** Slice used while idle so a stop request or signal is noticed promptly. */
    private const IDLE_SLICE_MICROS = 100_000;

    /** Retry a failed worker heartbeat after this long (seconds), at most. */
    private const WORKER_HEARTBEAT_RETRY_SECONDS = 5.0;

    /**
     * The lease renewal child stops extending a lease this long (seconds) after
     * the job's own timeout, in case the handler is stuck inside a native call
     * that the timeout signal cannot interrupt.
     */
    private const RENEWAL_TIMEOUT_GRACE_SECONDS = 2.0;

    /** A worker heartbeat in the child may not block longer than this share of the safe lease time. */
    private const MIN_WORKER_HEARTBEAT_BUDGET_SECONDS = 0.05;

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

    /** stop() arrived while start() was still registering. */
    private bool $stopRequested = false;

    /** When stop() was called (microtime); starts the shutdown-timeout clock. */
    private ?float $stopRequestedAt = null;

    /** True only while the user handler is on the stack; gates the timeout alarm. */
    private bool $handlerRunning = false;

    /** Absolute microtime at which the running handler exceeds its job timeout. */
    private ?float $jobDeadline = null;

    /** @var callable|int|null */
    private mixed $previousAlarmHandler = null;

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
     * This method blocks until stop() is called, then returns once the running
     * job (if any) has been acknowledged and the worker has deregistered.
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
            $registrationParams = [
                'queueName' => $this->config->queueName,
                'hostname' => $this->config->hostname ?? gethostname() ?: 'php-worker',
                'maxConcurrency' => $this->config->concurrency,
                'workerType' => $this->config->workerType,
                'version' => $this->config->version ?? Version::VERSION,
                'metadata' => $this->config->metadata ?? [],
            ];

            // A configured worker ID makes registration an upsert, so a restart
            // reuses this worker's row instead of leaving the old one occupying
            // a plan worker-cap slot until the stale-worker reaper clears it.
            if ($this->config->workerId !== null && $this->config->workerId !== '') {
                $registrationParams['workerId'] = $this->config->workerId;
            }

            $registration = $this->client->workers->register($registrationParams);

            $this->workerId = $registration->id;
            $this->debug("Worker registered: {$this->workerId}");

            $this->state = WorkerState::RUNNING;
            $this->emit(WorkerEvent::STARTED->value, [
                'workerId' => $this->workerId,
                'queueName' => $this->config->queueName,
            ]);

            // A stop() that arrived mid-registration is honoured now.
            if ($this->stopRequested) {
                $this->stop();
            }

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
     * Ask the worker to stop gracefully.
     *
     * This only requests the stop and returns at once, so it is safe to call
     * from a signal handler, which PHP runs on top of the handler that is
     * currently executing. The worker stops claiming jobs; a job already running
     * finishes and its result is sent (`complete`, or `fail` if the handler
     * threw); then the worker deregisters last and `start()` returns.
     *
     * If the running handler is still going `shutdownTimeout` milliseconds
     * after this call, it is aborted and the job is failed with
     * "Worker shutdown timeout". Handlers can poll `JobContext::isCancelled()`
     * to wrap up early.
     */
    public function stop(): void
    {
        if ($this->state === WorkerState::STARTING) {
            // Registration is still in flight; start() picks this up right after it.
            $this->stopRequested = true;

            return;
        }

        if ($this->state !== WorkerState::RUNNING) {
            return;
        }

        $this->state = WorkerState::STOPPING;
        $this->stopRequestedAt = microtime(true);
        $this->debug('Stop requested; the running job, if any, may finish');

        // Start the shutdown clock for a handler that is already running.
        if ($this->handlerRunning) {
            $this->rearmHandlerAlarm();
        }
    }

    /**
     * Main poll loop.
     */
    private function runPollLoop(): void
    {
        while ($this->state === WorkerState::RUNNING) {
            $this->dispatchSignals();
            if ($this->state !== WorkerState::RUNNING) {
                break;
            }

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
                    'limit' => min($availableSlots, self::MAX_CLAIM_PER_POLL),
                    'leaseDurationSecs' => $this->config->leaseDuration,
                ]);

                $this->processClaimedJobs($result->jobs);

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
     * Run the jobs of one claim, one after another.
     *
     * The claim asks for a single job, so this normally gets one. If the server
     * returns more, the ones waiting keep their leases renewed by the renewal
     * child (see runLeaseRenewalLoop) until their turn.
     *
     * @param array<ClaimedJob> $jobs
     */
    private function processClaimedJobs(array $jobs): void
    {
        foreach ($jobs as $index => $job) {
            // A job claimed before stop() was called is still ours and runs (the
            // shutdown timeout bounds it). Beyond that, no new work starts: the
            // rest of an over-delivered batch is left to its lease, which the
            // server recovers.
            if ($index > 0 && $this->state !== WorkerState::RUNNING) {
                $skipped = count($jobs) - $index;
                $this->logger->warning(
                    "[SpooledWorker] Stop requested; not starting {$skipped} claimed job(s), their leases will expire",
                );

                return;
            }

            $this->processJob($job, array_slice($jobs, $index + 1));
        }
    }

    /**
     * Process a single job.
     *
     * @param array<ClaimedJob> $pendingJobs Jobs claimed in the same batch that still wait for their turn
     */
    private function processJob(ClaimedJob $job, array $pendingJobs = []): void
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
        $returned = false;
        $result = null;

        try {
            $this->startLeaseRenewal($activeJob, $pendingJobs);
            $handlerStarted = true;

            try {
                $this->beginHandlerGuard($job);
                $result = ($this->handler)($context);
                $returned = true;
            } finally {
                $this->endHandlerGuard();
                // Renewal must be fully cancelled and reaped before settlement so
                // it can never race complete/fail or renew a replacement lease.
                $this->stopLeaseRenewal();
            }

            if ($activeJob->cancelled) {
                $this->debug("Job {$job->id} was cancelled");

                return;
            }

            // Completes even when stop() was requested meanwhile: a handler that
            // finished must never have its result dropped.
            $this->completeJob($job, $result);
        } catch (LeaseLostException $e) {
            $activeJob->cancelled = true;
            $this->reportWorkerError($e, $job, 'renew');
        } catch (JobTimeoutError|ShutdownTimeoutError $e) {
            $this->settleAbortedJob($job, $e, $returned, $result);
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
     * Settle a job whose handler was aborted by the job timeout or by the
     * shutdown timeout.
     *
     * If the handler had in fact already returned when the alarm landed (the
     * window is a few instructions), its result is sent, not discarded.
     */
    private function settleAbortedJob(ClaimedJob $job, Throwable $abort, bool $returned, mixed $result): void
    {
        if ($returned) {
            $this->completeJob($job, $result);

            return;
        }

        if ($abort instanceof JobTimeoutError) {
            $this->emit(WorkerEvent::JOB_TIMEOUT->value, [
                'jobId' => $job->id,
                'queueName' => $job->queueName,
                'timeoutSeconds' => $job->timeoutSeconds,
            ]);
            $this->failJob($job, 'Job timeout exceeded');

            return;
        }

        $this->failJob($job, 'Worker shutdown timeout');
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

        // The API rejects an empty or over-long message (400), which would leave
        // the job processing until its lease expired and lose the real error.
        $error = FailureText::coerce($error);
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

    /**
     * @param array<ClaimedJob> $pendingJobs Claimed jobs waiting behind this one; the child keeps their leases alive too
     */
    private function startLeaseRenewal(ActiveJob $activeJob, array $pendingJobs = []): void
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

            try {
                $this->runLeaseRenewalLoop($activeJob->job, $pendingJobs, $parentPid, $renewalOptions);
            } catch (Throwable) {
                // The loop never returns. If it somehow throws, the child must
                // not unwind into the parent's code path (it would run the job
                // a second time); tell the parent the lease can no longer be kept.
            }
            $this->signalLeaseLoss($parentPid);
        }

        pcntl_sigprocmask(SIG_SETMASK, $previousSignalMask);
        $this->leaseRenewalPid = $pid;
    }

    private function assertLeaseRenewalSupport(): void
    {
        if (!function_exists('pcntl_fork')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_sigprocmask')
            || !function_exists('pcntl_alarm')
            || !function_exists('posix_kill')
            || !function_exists('posix_setpgid')) {
            throw new RuntimeException(
                'SpooledWorker automatic lease renewal requires the pcntl and posix extensions.',
            );
        }
    }

    /** @param array<int> $previousSignalMask */
    private function configureRenewalChild(int $parentPid, array $previousSignalMask = []): void
    {
        $this->renewalChildCleanExit = false;
        // Own process group: a SIGTERM/SIGINT aimed at the worker's whole group
        // (Supervisor stopasgroup, Ctrl-C in a terminal, `kill -TERM -<pgid>`)
        // would otherwise kill this child too, and the job being drained would
        // lose lease renewal and worker heartbeat while it finishes.
        // The parent still ends this child by pid; if the parent dies instead,
        // exitIfOrphaned() makes it exit at its next wake-up without renewing.
        @posix_setpgid(0, 0);
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

    /**
     * Child process body: keeps the running job's lease (and those of any jobs
     * claimed with it that still wait) renewed, and sends the worker heartbeat,
     * until the parent ends it. Never returns.
     *
     * The worker heartbeat has to come from here: the parent is blocked inside
     * the handler, and the scheduler marks a worker offline after two minutes
     * without one, which releases (or dead-letters) the jobs of a perfectly
     * healthy worker that is simply busy with a long job.
     *
     * @param array<ClaimedJob> $pendingJobs
     */
    private function runLeaseRenewalLoop(
        ClaimedJob $job,
        array $pendingJobs,
        int $parentPid,
        ClientOptions $baseOptions,
    ): void {
        $leaseDuration = $this->config->leaseDuration;
        $renewEverySeconds = max(0.000001, $leaseDuration * $this->config->heartbeatFraction);
        $heartbeatEverySeconds = max(0.001, $this->config->heartbeatInterval / 1000);

        $startedAt = microtime(true);
        $leases = [$job->id => ['job' => $job, 'deadline' => $startedAt + $leaseDuration, 'current' => true]];
        foreach ($pendingJobs as $pendingJob) {
            $leases[$pendingJob->id] = ['job' => $pendingJob, 'deadline' => $startedAt + $leaseDuration, 'current' => false];
        }

        $giveUpAt = $job->timeoutSeconds > 0
            ? $startedAt + $job->timeoutSeconds + self::RENEWAL_TIMEOUT_GRACE_SECONDS
            : null;
        $nextRenewAt = $startedAt + $renewEverySeconds;
        $nextWorkerHeartbeatAt = max($startedAt, ($this->lastWorkerHeartbeat ?? 0.0) + $heartbeatEverySeconds);

        while (true) {
            $wakeAt = min($nextRenewAt, $nextWorkerHeartbeatAt, $giveUpAt ?? INF);
            usleep(max(1, (int) round(($wakeAt - microtime(true)) * 1_000_000)));
            $this->exitIfOrphaned($parentPid);

            if ($giveUpAt !== null && microtime(true) >= $giveUpAt) {
                // The handler is past its job timeout. The parent aborts it with
                // SIGALRM; reaching this point means it is stuck in a native call
                // that signal cannot interrupt. Stop extending the lease so the
                // server can recover the job instead of waiting on a hung process.
                $this->terminateRenewalChild();
            }

            if (microtime(true) >= $nextRenewAt) {
                $retryInSeconds = $this->renewLeases($leases, $parentPid, $baseOptions);
                $nextRenewAt = microtime(true) + ($retryInSeconds ?? $renewEverySeconds);
            }

            if (microtime(true) >= $nextWorkerHeartbeatAt) {
                $nextWorkerHeartbeatAt = microtime(true) + $this->sendWorkerHeartbeatFromChild(
                    $leases,
                    $baseOptions,
                    $heartbeatEverySeconds,
                );
            }
        }
    }

    /**
     * Renew every tracked lease once.
     *
     * A lost lease on the running job signals the parent (SIGUSR1) and ends the
     * child; a lost lease on a job that is still waiting just drops it from the
     * set, so the running handler is not disturbed by a job it has not reached.
     *
     * @param array<string, array{job: ClaimedJob, deadline: float, current: bool}> $leases
     * @return float|null Seconds until the next attempt when a renewal must be retried sooner, null when all succeeded
     */
    private function renewLeases(array &$leases, int $parentPid, ClientOptions $baseOptions): ?float
    {
        $retryInSeconds = null;

        foreach ($leases as $id => $lease) {
            $job = $lease['job'];

            try {
                $remainingSeconds = $lease['deadline'] - microtime(true);
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
                    $this->handleLostLease($leases, $id, $parentPid);

                    continue;
                }

                $leases[$id]['deadline'] = microtime(true) + $this->config->leaseDuration;
            } catch (ConflictError) {
                $this->handleLostLease($leases, $id, $parentPid);
            } catch (NetworkError|RateLimitError|ServerError|TimeoutError) {
                $remainingMicros = (int) (($lease['deadline'] - microtime(true)) * 1_000_000);
                if ($remainingMicros <= $this->renewalSafetyMarginMicros()) {
                    $this->handleLostLease($leases, $id, $parentPid);

                    continue;
                }

                $delaySeconds = max(1, min(1_000_000, $remainingMicros - $this->renewalSafetyMarginMicros())) / 1_000_000;
                $retryInSeconds = $retryInSeconds === null ? $delaySeconds : min($retryInSeconds, $delaySeconds);
            } catch (Throwable) {
                $this->handleLostLease($leases, $id, $parentPid);
            }
        }

        return $retryInSeconds;
    }

    /**
     * @param array<string, array{job: ClaimedJob, deadline: float, current: bool}> $leases
     */
    private function handleLostLease(array &$leases, string $jobId, int $parentPid): void
    {
        if ($leases[$jobId]['current']) {
            $this->signalLeaseLoss($parentPid);
        }

        unset($leases[$jobId]);
    }

    /**
     * Send one worker heartbeat from the renewal child.
     *
     * The call is bounded so it can never starve job lease renewal: it may use
     * at most half of the time the running job's lease still has beyond the
     * safety margin. Failures are ignored here (the next attempt follows
     * shortly); a missed heartbeat is not a reason to abort the job.
     *
     * @param array<string, array{job: ClaimedJob, deadline: float, current: bool}> $leases
     * @return float Seconds until the next attempt
     */
    private function sendWorkerHeartbeatFromChild(array $leases, ClientOptions $baseOptions, float $everySeconds): float
    {
        try {
            $remainingSeconds = INF;
            foreach ($leases as $lease) {
                $remainingSeconds = min($remainingSeconds, $lease['deadline'] - microtime(true));
            }

            $budget = min($baseOptions->requestTimeout, ($remainingSeconds - $this->renewalSafetyMarginSeconds()) / 2);
            if ($budget < self::MIN_WORKER_HEARTBEAT_BUDGET_SECONDS) {
                // The lease is about to need renewing; let that go first.
                return min($everySeconds, 0.25);
            }

            $workers = $this->createRenewalWorkersResource($this->renewalClientOptions($baseOptions, $budget));
            $workers->heartbeat((string) $this->workerId, [
                'currentJobs' => count($this->activeJobs),
                'status' => 'healthy',
            ]);

            return $everySeconds;
        } catch (Throwable) {
            return min($everySeconds, self::WORKER_HEARTBEAT_RETRY_SECONDS);
        }
    }

    protected function createRenewalJobsResource(ClientOptions $options): JobsResource
    {
        return (new SpooledClient($options))->jobs;
    }

    protected function createRenewalWorkersResource(ClientOptions $options): WorkersResource
    {
        return (new SpooledClient($options))->workers;
    }

    private function buildRenewalClientOptions(ClientOptions $baseOptions, float $remainingSeconds): ClientOptions
    {
        $requestBudget = $remainingSeconds - $this->renewalSafetyMarginSeconds();
        if ($requestBudget <= 0) {
            throw new TimeoutError('Insufficient lease time remains for a safe renewal request.');
        }

        return $this->renewalClientOptions($baseOptions, $requestBudget);
    }

    /**
     * Options for a request made from the renewal child: no retries and no
     * circuit breaker (the child's own loop decides when to try again, against
     * the lease clock), and a timeout that fits the given time budget.
     */
    private function renewalClientOptions(ClientOptions $baseOptions, float $requestBudget): ClientOptions
    {
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
            // The call already retried; do not hammer an unreachable API on every
            // poll. Try again after a few seconds (sooner if the interval is shorter).
            $this->lastWorkerHeartbeat = $now - $interval + min($interval, self::WORKER_HEARTBEAT_RETRY_SECONDS);
        }
    }

    /**
     * Arm the timers that can abort the handler that is about to run: the job's
     * own timeout, and (once stop() was called) the shutdown timeout.
     *
     * Both ride on one SIGALRM. The handler raised in the signal handler unwinds
     * through whatever the user's code is doing, as the lease-loss signal
     * already does. A handler blocked inside a native call (curl_exec without a
     * timeout, a blocking socket read) only sees it once that call returns.
     */
    private function beginHandlerGuard(ClaimedJob $job): void
    {
        $this->jobDeadline = $job->timeoutSeconds > 0 ? microtime(true) + $job->timeoutSeconds : null;
        $this->previousAlarmHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, function (): void {
            $this->onHandlerAlarm();
        });
        $this->handlerRunning = true;
        $this->rearmHandlerAlarm();
    }

    private function endHandlerGuard(): void
    {
        $this->handlerRunning = false;
        $this->jobDeadline = null;
        pcntl_alarm(0);

        if ($this->previousAlarmHandler !== null) {
            pcntl_signal(SIGALRM, $this->previousAlarmHandler);
            $this->previousAlarmHandler = null;
        }
    }

    /**
     * (Re)schedule the alarm for the earliest deadline that applies. SIGALRM has
     * whole-second resolution, so it may fire up to a second after the deadline.
     */
    private function rearmHandlerAlarm(): void
    {
        $deadlines = array_filter([$this->jobDeadline, $this->shutdownDeadline()], static fn (?float $d): bool => $d !== null);
        if ($deadlines === []) {
            pcntl_alarm(0);

            return;
        }

        pcntl_alarm(max(1, (int) ceil(min($deadlines) - microtime(true))));
    }

    private function shutdownDeadline(): ?float
    {
        return $this->stopRequestedAt === null
            ? null
            : $this->stopRequestedAt + $this->config->shutdownTimeout / 1000;
    }

    /**
     * SIGALRM handler: abort the running handler once a deadline has passed.
     */
    private function onHandlerAlarm(): void
    {
        if (!$this->handlerRunning) {
            return;
        }

        $now = microtime(true);
        if ($this->jobDeadline !== null && $now >= $this->jobDeadline) {
            $this->handlerRunning = false;

            throw new JobTimeoutError('Job timeout exceeded');
        }

        $shutdownDeadline = $this->shutdownDeadline();
        if ($shutdownDeadline !== null && $now >= $shutdownDeadline) {
            $this->handlerRunning = false;

            throw new ShutdownTimeoutError('Worker shutdown timeout');
        }

        // Woke before any deadline (second rounding, or a deadline that moved): wait for the rest.
        $this->rearmHandlerAlarm();
    }

    /**
     * Run pending signal handlers. Needed when the application registered
     * pcntl_signal() handlers without enabling async signals: PHP then only runs
     * them when asked.
     */
    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
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
        if ($this->state !== WorkerState::ERROR) {
            $this->state = WorkerState::STOPPED;
        }
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
     * Sleep for milliseconds, in short slices so a stop request is noticed
     * promptly and pending signal handlers get to run.
     */
    private function sleep(int $ms): void
    {
        $deadline = microtime(true) + $ms / 1000;

        while (($remaining = $deadline - microtime(true)) > 0) {
            usleep((int) min(self::IDLE_SLICE_MICROS, $remaining * 1_000_000));
            $this->dispatchSignals();

            if ($this->state !== WorkerState::RUNNING) {
                return;
            }
        }
    }
}
