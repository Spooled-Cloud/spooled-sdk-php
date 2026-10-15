<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Worker;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Spooled\Config\ClientOptions;
use Spooled\Errors\ConflictError;
use Spooled\Http\HttpClient;
use Spooled\Resources\JobsResource;
use Spooled\Resources\WorkersResource;
use Spooled\SpooledClient;
use Spooled\Types\ClaimedJob;
use Spooled\Version;
use Spooled\Worker\JobContext;
use Spooled\Worker\SpooledWorker;
use Spooled\Worker\WorkerState;
use stdClass;
use Stringable;

final class CallbackHttpClient extends HttpClient
{
    /** @param callable(string, ?array<string, mixed>): array<string, mixed> $responder */
    public function __construct(private readonly mixed $responder)
    {
    }

    /** @return array<string, mixed> */
    public function post(
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
        bool $forceRetry = false,
    ): array {
        return ($this->responder)($path, $body);
    }
}

final class TestSpooledWorker extends SpooledWorker
{
    /** @var callable(string, ?array<string, mixed>): array<string, mixed>|null */
    public mixed $renewalResponder = null;

    public ?string $renewalOptionsFile = null;

    /** File the renewal child appends its worker heartbeats to (the child cannot share memory). */
    public ?string $workerHeartbeatFile = null;

    /** Make every worker heartbeat from the renewal child fail, as an unreachable API would. */
    public bool $failWorkerHeartbeats = false;

    protected function createRenewalWorkersResource(ClientOptions $options): WorkersResource
    {
        $file = $this->workerHeartbeatFile;
        $fail = $this->failWorkerHeartbeats;

        return new WorkersResource(new CallbackHttpClient(
            static function (string $path, ?array $body) use ($file, $fail): array {
                if ($fail) {
                    throw new \Spooled\Errors\NetworkError('connection refused');
                }
                if ($file !== null) {
                    file_put_contents($file, json_encode(['path' => $path, 'body' => $body]) . "\n", FILE_APPEND);
                }

                return ['success' => true];
            },
        ));
    }

    protected function createRenewalJobsResource(ClientOptions $options): JobsResource
    {
        if ($this->renewalOptionsFile !== null) {
            file_put_contents($this->renewalOptionsFile, json_encode([
                'connectTimeout' => $options->connectTimeout,
                'requestTimeout' => $options->requestTimeout,
                'maxRetries' => $options->retry->maxRetries,
                'circuitBreakerEnabled' => $options->circuitBreaker->enabled,
                'transportId' => uniqid('', true),
            ]) . "\n", FILE_APPEND);
        }

        return new JobsResource(new CallbackHttpClient(
            $this->renewalResponder ?? static fn (): array => ['success' => true],
        ));
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var array<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
}

#[CoversClass(SpooledWorker::class)]
final class SpooledWorkerTest extends TestCase
{
    /** @var array<array{path: string, body: array<string, mixed>|null}> */
    private array $posts = [];

    /**
     * @param array<string, mixed> $options
     * @param callable(string, ?array<string, mixed>): array<string, mixed>|null $responder
     */
    private function createWorker(
        array $options = [],
        ?callable $responder = null,
        ?LoggerInterface $logger = null,
    ): SpooledWorker {
        $this->posts = [];

        $httpClient = new CallbackHttpClient(
            function (string $path, ?array $body = null) use ($responder): array {
                $this->posts[] = ['path' => $path, 'body' => $body];

                if ($path === 'workers/register' && $responder === null) {
                    return ['id' => 'worker-1'];
                }

                return $responder?->__invoke($path, $body) ?? ['success' => true];
            },
        );

        $effectiveLogger = $logger ?? new NullLogger();
        $clientReflection = new ReflectionClass(SpooledClient::class);
        $client = $clientReflection->newInstanceWithoutConstructor();
        $clientReflection->getProperty('jobs')->setValue($client, new JobsResource($httpClient));
        $clientReflection->getProperty('workers')->setValue($client, new WorkersResource($httpClient));
        $clientReflection->getProperty('logger')->setValue($client, $effectiveLogger);
        $clientReflection->getProperty('options')->setValue($client, new ClientOptions(
            baseUrl: 'http://127.0.0.1:1',
            connectTimeout: 10,
            requestTimeout: 30,
            logger: $effectiveLogger,
        ));

        $worker = new TestSpooledWorker(
            $client,
            array_merge(['queueName' => 'test-queue'], $options),
            $effectiveLogger,
        );

        $worker->renewalResponder = $responder;

        $workerReflection = new ReflectionClass(SpooledWorker::class);
        $workerReflection->getProperty('workerId')->setValue($worker, 'worker-1');

        return $worker;
    }

    private function claimedJob(?string $leaseId, string $id = 'job-123', int $timeoutSeconds = 300): ClaimedJob
    {
        return new ClaimedJob(
            id: $id,
            queueName: 'test-queue',
            payload: ['key' => 'value'],
            retryCount: 0,
            maxRetries: 3,
            timeoutSeconds: $timeoutSeconds,
            leaseExpiresAt: null,
            leaseId: $leaseId,
        );
    }

    /**
     * Responder for a full start() run: registers as worker-1, hands out the
     * given claim responses in order and then empty claims, acks with success.
     *
     * @param array<int, array<string, mixed>> $jobs Raw claim payloads, one claim response each
     * @return callable(string, ?array<string, mixed>): array<string, mixed>
     */
    private function lifecycleResponder(array $jobs, ?callable $onClaim = null, ?callable $onRegister = null): callable
    {
        $claims = 0;

        return function (string $path, ?array $body) use (&$claims, $jobs, $onClaim, $onRegister): array {
            if ($path === 'workers/register') {
                if ($onRegister !== null) {
                    $onRegister();
                }

                return ['id' => 'worker-1'];
            }
            if ($path === 'jobs/claim') {
                $claims++;
                if ($onClaim !== null) {
                    $onClaim($claims, $body);
                }

                return ['jobs' => $jobs[$claims - 1] ?? []];
            }

            return ['success' => true];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function rawJob(string $id = 'job-1', int $timeoutSeconds = 300): array
    {
        return [
            'id' => $id,
            'queueName' => 'test-queue',
            'payload' => ['n' => 1],
            'retryCount' => 0,
            'maxRetries' => 3,
            'timeoutSeconds' => $timeoutSeconds,
            'leaseId' => "lease-{$id}",
        ];
    }

    /**
     * start() normally moves the worker to RUNNING; tests that drive processJob /
     * processClaimedJobs directly do it by hand.
     */
    private function markRunning(SpooledWorker $worker): void
    {
        (new ReflectionClass(SpooledWorker::class))->getProperty('state')
            ->setValue($worker, WorkerState::RUNNING);
    }

    /**
     * @return array<int, string>
     */
    private function postedPaths(): array
    {
        return array_values(array_filter(
            array_column($this->posts, 'path'),
            static fn (string $path): bool => !str_ends_with($path, '/heartbeat'),
        ));
    }

    private function invokePrivate(SpooledWorker $worker, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(SpooledWorker::class))
            ->getMethod($method)
            ->invoke($worker, ...$args);
    }

    #[Test]
    public function it_echoes_lease_id_on_complete(): void
    {
        $worker = $this->createWorker();

        $this->invokePrivate($worker, 'completeJob', $this->claimedJob('lease-abc'), ['ok' => true]);

        $this->assertCount(1, $this->posts);
        $this->assertSame('jobs/job-123/complete', $this->posts[0]['path']);
        $this->assertSame('worker-1', $this->posts[0]['body']['workerId']);
        $this->assertSame('lease-abc', $this->posts[0]['body']['leaseId']);
    }

    #[Test]
    public function it_echoes_lease_id_on_fail(): void
    {
        $worker = $this->createWorker();

        $this->invokePrivate($worker, 'failJob', $this->claimedJob('lease-abc'), 'boom');

        $this->assertCount(1, $this->posts);
        $this->assertSame('jobs/job-123/fail', $this->posts[0]['path']);
        $this->assertSame('worker-1', $this->posts[0]['body']['workerId']);
        $this->assertSame('boom', $this->posts[0]['body']['error']);
        $this->assertSame('lease-abc', $this->posts[0]['body']['leaseId']);
    }

    #[Test]
    public function it_omits_lease_id_when_claim_did_not_return_one(): void
    {
        $worker = $this->createWorker();

        $this->invokePrivate($worker, 'completeJob', $this->claimedJob(null), null);
        $this->invokePrivate($worker, 'failJob', $this->claimedJob(null), 'boom');

        $this->assertCount(2, $this->posts);
        $this->assertArrayNotHasKey('leaseId', $this->posts[0]['body']);
        $this->assertArrayNotHasKey('leaseId', $this->posts[1]['body']);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function it_renews_a_lease_while_a_synchronous_handler_is_running(): void
    {
        $heartbeatFile = tempnam(sys_get_temp_dir(), 'spooled-heartbeats-');
        $optionsFile = tempnam(sys_get_temp_dir(), 'spooled-renew-options-');
        $this->assertNotFalse($heartbeatFile);
        $this->assertNotFalse($optionsFile);

        try {
            $worker = $this->createWorker(
                ['leaseDuration' => 1, 'heartbeatFraction' => 0.1],
                function (string $path, ?array $body) use ($heartbeatFile): array {
                    if (str_ends_with($path, '/heartbeat')) {
                        file_put_contents($heartbeatFile, json_encode($body) . "\n", FILE_APPEND);
                    }

                    return ['success' => true];
                },
            );
            $this->assertInstanceOf(TestSpooledWorker::class, $worker);
            $worker->renewalOptionsFile = $optionsFile;
            $worker->process(static function (): array {
                usleep(350_000);

                return ['ok' => true];
            });

            $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-current'));

            $heartbeats = file($heartbeatFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertNotFalse($heartbeats);
            $this->assertGreaterThanOrEqual(2, count($heartbeats));
            foreach ($heartbeats as $heartbeat) {
                $body = json_decode($heartbeat, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('worker-1', $body['workerId']);
                $this->assertSame(1, $body['leaseDurationSecs']);
                $this->assertSame('lease-current', $body['leaseId']);
            }

            $renewalOptions = file($optionsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertNotFalse($renewalOptions);
            $this->assertGreaterThanOrEqual(2, count($renewalOptions));
            $transportIds = [];
            foreach ($renewalOptions as $serializedOptions) {
                $options = json_decode($serializedOptions, true, flags: JSON_THROW_ON_ERROR);
                $this->assertGreaterThan(0, $options['requestTimeout']);
                $this->assertLessThan(0.9, $options['requestTimeout']);
                $this->assertLessThanOrEqual($options['requestTimeout'], $options['connectTimeout']);
                $this->assertSame(0, $options['maxRetries']);
                $this->assertFalse($options['circuitBreakerEnabled']);
                $transportIds[] = $options['transportId'];
            }
            $this->assertCount(count($transportIds), array_unique($transportIds));
            $this->assertSame(1, $worker->getCompletedJobs());
            $this->assertSame(0, $worker->getActiveJobCount());
        } finally {
            @unlink($heartbeatFile);
            @unlink($optionsFile);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function it_cancels_the_handler_and_does_not_settle_after_lease_expiry(): void
    {
        $logger = new RecordingLogger();
        $errors = [];
        $worker = $this->createWorker(
            ['leaseDuration' => 1, 'heartbeatFraction' => 0.1],
            static function (string $path): array {
                if (str_ends_with($path, '/heartbeat')) {
                    throw new ConflictError('lease expired', 'LEASE_EXPIRED');
                }

                return ['success' => true];
            },
            $logger,
        );
        $worker->on('error', static function (array $event) use (&$errors): void {
            $errors[] = $event;
        });
        $worker->process(static function (): array {
            sleep(2);

            return ['should-not-complete' => true];
        });

        $this->invokePrivate($worker, 'processJob', $this->claimedJob('stale-lease'));

        $this->assertSame(0, $worker->getCompletedJobs());
        $this->assertSame(0, $worker->getFailedJobs());
        $this->assertSame(0, $worker->getActiveJobCount());
        $this->assertCount(1, $errors);
        $this->assertSame('renew', $errors[0]['operation']);
        $this->assertSame('stale-lease', $errors[0]['leaseId']);
        $this->assertNotEmpty($logger->records);
        $this->assertSame([], array_filter(
            $this->posts,
            static fn (array $post): bool => str_ends_with($post['path'], '/complete') || str_ends_with($post['path'], '/fail'),
        ));
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function renewal_child_termination_is_clean_and_does_not_signal_the_parent(): void
    {
        $worker = $this->createWorker();
        $parentSignalled = false;
        $previousHandler = pcntl_signal_get_handler(SIGUSR1);
        $previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, static function () use (&$parentSignalled): void {
            $parentSignalled = true;
        });

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                $this->invokePrivate($worker, 'configureRenewalChild', posix_getppid());
                fwrite($sockets[1], 'ready');
                fclose($sockets[1]);
                while (true) {
                    usleep(100_000);
                }
            }

            fclose($sockets[1]);
            $this->assertSame('ready', stream_get_contents($sockets[0]));
            fclose($sockets[0]);
            posix_kill($pid, SIGTERM);
            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            usleep(20_000);

            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            $this->assertFalse($parentSignalled);
        } finally {
            pcntl_signal(SIGUSR1, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function renewal_child_exits_when_its_original_parent_identity_no_longer_matches(): void
    {
        $worker = $this->createWorker();
        $parentSignalled = false;
        $previousHandler = pcntl_signal_get_handler(SIGUSR1);
        $previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, static function () use (&$parentSignalled): void {
            $parentSignalled = true;
        });

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                $this->invokePrivate($worker, 'configureRenewalChild', posix_getppid());
                $this->invokePrivate($worker, 'exitIfOrphaned', posix_getppid() + 1);
            }

            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            usleep(20_000);
            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            $this->assertFalse($parentSignalled);
        } finally {
            pcntl_signal(SIGUSR1, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function it_escalates_and_reaps_an_uncooperative_renewal_child(): void
    {
        $worker = $this->createWorker();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_IGN);
            fwrite($sockets[1], 'ready');
            fclose($sockets[1]);
            while (true) {
                usleep(100_000);
            }
        }

        fclose($sockets[1]);
        $this->assertSame('ready', stream_get_contents($sockets[0]));
        fclose($sockets[0]);
        (new ReflectionClass(SpooledWorker::class))->getProperty('leaseRenewalPid')->setValue($worker, $pid);
        $startedAt = microtime(true);
        $this->invokePrivate($worker, 'stopLeaseRenewal');
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(1.0, $elapsed);
        $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
        $this->assertSame([], (new ReflectionClass(SpooledWorker::class))
            ->getProperty('unreapedRenewalPids')
            ->getValue($worker));
    }

    #[Test]
    public function it_emits_error_not_success_when_completion_is_rejected(): void
    {
        $logger = new RecordingLogger();
        $completed = [];
        $errors = [];
        $worker = $this->createWorker([], static fn (): array => ['success' => false], $logger);
        $worker->on('job:completed', static function (array $event) use (&$completed): void {
            $completed[] = $event;
        });
        $worker->on('error', static function (array $event) use (&$errors): void {
            $errors[] = $event;
        });

        $this->invokePrivate($worker, 'completeJob', $this->claimedJob('lease-abc'), ['ok' => true]);

        $this->assertSame(0, $worker->getCompletedJobs());
        $this->assertSame([], $completed);
        $this->assertCount(1, $errors);
        $this->assertSame('complete', $errors[0]['operation']);
        $this->assertSame('error', $logger->records[0]['level']);
    }

    #[Test]
    public function it_emits_error_not_failed_when_failure_settlement_is_rejected(): void
    {
        $logger = new RecordingLogger();
        $failed = [];
        $errors = [];
        $worker = $this->createWorker([], static fn (): never => throw new RuntimeException('rejected'), $logger);
        $worker->on('job:failed', static function (array $event) use (&$failed): void {
            $failed[] = $event;
        });
        $worker->on('error', static function (array $event) use (&$errors): void {
            $errors[] = $event;
        });

        $this->invokePrivate($worker, 'failJob', $this->claimedJob('lease-abc'), 'handler failed');

        $this->assertSame(0, $worker->getFailedJobs());
        $this->assertSame([], $failed);
        $this->assertCount(1, $errors);
        $this->assertSame('fail', $errors[0]['operation']);
        $this->assertSame('error', $logger->records[0]['level']);
    }

    #[Test]
    public function package_version_is_the_default_http_and_worker_version(): void
    {
        $this->assertSame('1.5.0', Version::VERSION);
        $this->assertSame(Version::USER_AGENT, ClientOptions::DEFAULT_USER_AGENT);
    }

    // --- SDKGP-19: claim only what will run now; renew every claimed job ---

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function it_claims_one_job_per_poll_whatever_the_concurrency(): void
    {
        $ref = new stdClass();
        $limits = [];
        $worker = $this->createWorker(
            ['concurrency' => 5, 'pollInterval' => 10, 'shutdownTimeout' => 1000],
            $this->lifecycleResponder([], function (int $claim, ?array $body) use (&$limits, $ref): void {
                $limits[] = $body['limit'];
                if ($claim >= 2) {
                    $ref->worker->stop();
                }
            }),
        );
        $ref->worker = $worker;
        $worker->process(static fn (): array => []);

        $worker->start();

        // Claiming min(5, 10) jobs for a serial runner parked 4 of them on a
        // lease that expires while they wait.
        $this->assertSame([1, 1], $limits);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function it_keeps_the_leases_of_claimed_jobs_that_are_still_waiting_alive(): void
    {
        $heartbeatFile = tempnam(sys_get_temp_dir(), 'spooled-pending-');
        $this->assertNotFalse($heartbeatFile);

        try {
            $worker = $this->createWorker(
                ['leaseDuration' => 1, 'heartbeatFraction' => 0.1],
                function (string $path, ?array $body) use ($heartbeatFile): array {
                    if (str_ends_with($path, '/heartbeat')) {
                        file_put_contents($heartbeatFile, $path . "\n", FILE_APPEND);
                    }

                    return ['success' => true];
                },
            );
            $runs = 0;
            $worker->process(static function () use (&$runs): array {
                // Only the first handler is slow; job-2 and job-3 wait behind it.
                if ($runs++ === 0) {
                    usleep(600_000);
                }

                return ['ok' => true];
            });

            $this->markRunning($worker);
            $this->invokePrivate($worker, 'processClaimedJobs', [
                $this->claimedJob('lease-1', 'job-1'),
                $this->claimedJob('lease-2', 'job-2'),
                $this->claimedJob('lease-3', 'job-3'),
            ]);

            $heartbeats = array_count_values(file($heartbeatFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            $this->assertGreaterThanOrEqual(2, $heartbeats['jobs/job-1/heartbeat'] ?? 0);
            $this->assertGreaterThanOrEqual(2, $heartbeats['jobs/job-2/heartbeat'] ?? 0);
            $this->assertGreaterThanOrEqual(2, $heartbeats['jobs/job-3/heartbeat'] ?? 0);
            $this->assertSame(3, $worker->getCompletedJobs());
        } finally {
            @unlink($heartbeatFile);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_lost_lease_on_a_waiting_job_does_not_abort_the_running_handler(): void
    {
        $worker = $this->createWorker(
            ['leaseDuration' => 1, 'heartbeatFraction' => 0.1],
            static function (string $path): array {
                if ($path === 'jobs/job-2/heartbeat') {
                    throw new ConflictError('lease expired', 'LEASE_EXPIRED');
                }

                return ['success' => true];
            },
        );
        $worker->process(static function (): array {
            usleep(400_000);

            return ['ok' => true];
        });

        $this->markRunning($worker);
        $this->invokePrivate($worker, 'processClaimedJobs', [
            $this->claimedJob('lease-1', 'job-1'),
            $this->claimedJob('lease-2', 'job-2'),
        ]);

        // job-1 was not interrupted by job-2's 409.
        $this->assertContains('jobs/job-1/complete', array_column($this->posts, 'path'));
        $this->assertSame(0, $worker->getFailedJobs());
    }

    // --- SDKGP-20: worker heartbeat while a handler runs ---

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function the_renewal_child_sends_worker_heartbeats_while_a_handler_runs(): void
    {
        $heartbeatFile = tempnam(sys_get_temp_dir(), 'spooled-worker-hb-');
        $this->assertNotFalse($heartbeatFile);

        try {
            $worker = $this->createWorker(['leaseDuration' => 1, 'heartbeatFraction' => 0.1, 'heartbeatInterval' => 200]);
            $this->assertInstanceOf(TestSpooledWorker::class, $worker);
            $worker->workerHeartbeatFile = $heartbeatFile;
            $worker->process(static function (): array {
                usleep(1_000_000);

                return ['ok' => true];
            });

            $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1'));

            $lines = file($heartbeatFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertNotFalse($lines);
            // 1 s handler, 200 ms interval: without this the scheduler sees no
            // heartbeat for the whole job and marks the worker offline after 2 minutes.
            $this->assertGreaterThanOrEqual(3, count($lines));
            $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('workers/worker-1/heartbeat', $first['path']);
            $this->assertSame('healthy', $first['body']['status']);
            $this->assertSame(1, $first['body']['currentJobs']);
        } finally {
            @unlink($heartbeatFile);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_failing_worker_heartbeat_never_disturbs_the_job(): void
    {
        $worker = $this->createWorker(['leaseDuration' => 1, 'heartbeatFraction' => 0.1, 'heartbeatInterval' => 100]);
        $this->assertInstanceOf(TestSpooledWorker::class, $worker);
        $worker->failWorkerHeartbeats = true;
        $worker->process(static function (): array {
            usleep(500_000);

            return ['ok' => true];
        });

        $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1'));

        $this->assertSame(1, $worker->getCompletedJobs());
        $this->assertSame(0, $worker->getFailedJobs());
        $this->assertContains('jobs/job-123/complete', array_column($this->posts, 'path'));
    }

    // --- SDKGP-21: stop() drains instead of blocking and dropping the ack ---

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function stop_called_mid_job_lets_the_job_finish_ack_and_deregister_last(): void
    {
        $ref = new stdClass();
        $worker = $this->createWorker(
            ['pollInterval' => 10, 'shutdownTimeout' => 1000],
            $this->lifecycleResponder([[$this->rawJob()]]),
        );
        $ref->worker = $worker;
        $completed = [];
        $stopped = [];
        $sawShutdown = null;
        $worker->on('job:completed', static function (array $event) use (&$completed): void {
            $completed[] = $event['jobId'];
        });
        $worker->on('stopped', static function (array $event) use (&$stopped): void {
            $stopped[] = $event;
        });
        $worker->process(static function (JobContext $ctx) use ($ref, &$sawShutdown): array {
            $ref->worker->stop();
            $sawShutdown = $ctx->isCancelled();
            usleep(300_000);

            return ['done' => true];
        });

        $startedAt = microtime(true);
        $worker->start();

        // The result of the job that was running is acknowledged, then the
        // worker deregisters last; nothing new was claimed after stop().
        $this->assertSame(
            ['workers/register', 'jobs/claim', 'jobs/job-1/complete', 'workers/worker-1/deregister'],
            $this->postedPaths(),
        );
        $complete = array_values(array_filter($this->posts, static fn (array $p): bool => $p['path'] === 'jobs/job-1/complete'))[0];
        $this->assertSame(['done' => true], $complete['body']['result']);
        $this->assertTrue($sawShutdown, 'the handler can see the shutdown and wrap up early');
        $this->assertSame(['job-1'], $completed);
        $this->assertSame(1, $worker->getCompletedJobs());
        $this->assertSame(0, $worker->getFailedJobs());
        $this->assertSame('stopped', $worker->getState());
        $this->assertCount(1, $stopped);
        $this->assertLessThan(5.0, microtime(true) - $startedAt);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_sigterm_handler_that_calls_stop_does_not_block_the_running_job(): void
    {
        $previousHandler = pcntl_signal_get_handler(SIGTERM);
        $previousAsync = pcntl_async_signals(true);
        $ref = new stdClass();

        try {
            $worker = $this->createWorker(
                ['pollInterval' => 10, 'shutdownTimeout' => 3000],
                $this->lifecycleResponder([[$this->rawJob()]]),
            );
            $ref->worker = $worker;
            pcntl_signal(SIGTERM, static function () use ($ref): void {
                $ref->worker->stop();
            });
            $worker->process(static function (): array {
                // SIGTERM lands while this handler is on the stack, exactly as
                // the documented `pcntl_signal(SIGTERM, fn () => $worker->stop())`.
                posix_kill(posix_getpid(), SIGTERM);
                usleep(300_000);

                return ['done' => true];
            });

            $startedAt = microtime(true);
            $worker->start();
            $elapsed = microtime(true) - $startedAt;

            // Before the fix the signal handler blocked for the whole
            // shutdownTimeout and the finished job was never acknowledged.
            $this->assertContains('jobs/job-1/complete', $this->postedPaths());
            $this->assertNotContains('jobs/job-1/fail', $this->postedPaths());
            $this->assertSame('workers/worker-1/deregister', $this->posts[array_key_last($this->posts)]['path']);
            $this->assertLessThan(2.0, $elapsed, 'stop() must not wait out the 3 s shutdownTimeout');
        } finally {
            pcntl_signal(SIGTERM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_handler_that_throws_during_shutdown_is_still_failed(): void
    {
        $ref = new stdClass();
        $worker = $this->createWorker(
            ['pollInterval' => 10, 'shutdownTimeout' => 1000],
            $this->lifecycleResponder([[$this->rawJob()]]),
        );
        $ref->worker = $worker;
        $worker->process(static function () use ($ref): void {
            $ref->worker->stop();

            throw new RuntimeException('upstream exploded');
        });

        $worker->start();

        $fail = array_values(array_filter($this->posts, static fn (array $p): bool => $p['path'] === 'jobs/job-1/fail'))[0];
        $this->assertSame('upstream exploded', $fail['body']['error']);
        $this->assertSame('workers/worker-1/deregister', $this->posts[array_key_last($this->posts)]['path']);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function the_shutdown_timeout_aborts_a_stuck_handler_and_fails_the_job(): void
    {
        $ref = new stdClass();
        $worker = $this->createWorker(
            ['pollInterval' => 10, 'shutdownTimeout' => 1000],
            $this->lifecycleResponder([[$this->rawJob()]]),
        );
        $ref->worker = $worker;
        $worker->process(static function () use ($ref): array {
            $ref->worker->stop();
            sleep(20);

            return ['should-not-complete' => true];
        });

        $startedAt = microtime(true);
        $worker->start();
        $elapsed = microtime(true) - $startedAt;

        $fail = array_values(array_filter($this->posts, static fn (array $p): bool => $p['path'] === 'jobs/job-1/fail'))[0];
        $this->assertSame('Worker shutdown timeout', $fail['body']['error']);
        $this->assertNotContains('jobs/job-1/complete', $this->postedPaths());
        $this->assertSame('workers/worker-1/deregister', $this->posts[array_key_last($this->posts)]['path']);
        $this->assertLessThan(6.0, $elapsed);
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_stop_requested_during_registration_is_honoured(): void
    {
        $ref = new stdClass();
        $worker = $this->createWorker(
            ['pollInterval' => 10],
            $this->lifecycleResponder([[$this->rawJob()]], onRegister: static function () use ($ref): void {
                $ref->worker->stop();
            }),
        );
        $ref->worker = $worker;
        $worker->process(static fn (): array => ['should-not-run' => true]);

        $worker->start();

        $this->assertSame(['workers/register', 'workers/worker-1/deregister'], $this->postedPaths());
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function jobs_claimed_alongside_the_running_one_are_not_started_after_a_stop(): void
    {
        $ref = new stdClass();
        $worker = $this->createWorker(['leaseDuration' => 30]);
        $ref->worker = $worker;
        $ran = [];
        $worker->process(static function (JobContext $ctx) use ($ref, &$ran): array {
            $ran[] = $ctx->jobId;
            $ref->worker->stop();

            return ['ok' => true];
        });
        $this->markRunning($worker);

        $this->invokePrivate($worker, 'processClaimedJobs', [
            $this->claimedJob('lease-1', 'job-1'),
            $this->claimedJob('lease-2', 'job-2'),
        ]);

        $this->assertSame(['job-1'], $ran);
        $this->assertContains('jobs/job-1/complete', array_column($this->posts, 'path'));
    }

    // --- Brief 5: job timeout ---

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_handler_past_its_job_timeout_is_aborted_and_the_job_failed(): void
    {
        $timedOut = [];
        $worker = $this->createWorker();
        $worker->on('job:timeout', static function (array $event) use (&$timedOut): void {
            $timedOut[] = $event;
        });
        $worker->process(static function (): array {
            sleep(20);

            return ['should-not-complete' => true];
        });

        $startedAt = microtime(true);
        $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1', 'job-123', 1));
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(5.0, $elapsed);
        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $fail = array_values(array_filter($this->posts, static fn (array $p): bool => $p['path'] === 'jobs/job-123/fail'))[0];
        $this->assertSame('Job timeout exceeded', $fail['body']['error']);
        $this->assertSame('lease-1', $fail['body']['leaseId']);
        $this->assertNotContains('jobs/job-123/complete', array_column($this->posts, 'path'));
        $this->assertCount(1, $timedOut);
        $this->assertSame('job-123', $timedOut[0]['jobId']);
        $this->assertSame(1, $worker->getFailedJobs());
        $this->assertSame(0, pcntl_alarm(0), 'no alarm may outlive the handler');
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_handler_cannot_swallow_the_timeout_with_catch_exception(): void
    {
        $worker = $this->createWorker();
        $worker->process(static function (): array {
            try {
                sleep(20);
            } catch (Exception) {
                return ['swallowed' => true];
            }

            return ['should-not-complete' => true];
        });

        $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1', 'job-123', 1));

        $paths = array_column($this->posts, 'path');
        $this->assertContains('jobs/job-123/fail', $paths);
        $this->assertNotContains('jobs/job-123/complete', $paths);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_job_that_finishes_in_time_completes_and_leaves_no_alarm(): void
    {
        $worker = $this->createWorker();
        $worker->process(static function (): array {
            usleep(100_000);

            return ['ok' => true];
        });

        $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1', 'job-123', 30));

        $this->assertContains('jobs/job-123/complete', array_column($this->posts, 'path'));
        $this->assertSame(0, pcntl_alarm(0));
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_zero_timeout_means_no_deadline(): void
    {
        $worker = $this->createWorker();
        $alarmDuringHandler = null;
        $worker->process(static function () use (&$alarmDuringHandler): array {
            $alarmDuringHandler = pcntl_alarm(0);

            return ['ok' => true];
        });

        $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1', 'job-123', 0));

        $this->assertSame(0, $alarmDuringHandler);
        $this->assertContains('jobs/job-123/complete', array_column($this->posts, 'path'));
    }

    // --- Brief 4 / SDKGP-12: failure text ---

    #[Test]
    public function the_worker_sends_a_default_for_an_empty_error_and_truncates_a_long_one(): void
    {
        $worker = $this->createWorker();
        $events = [];
        $worker->on('job:failed', static function (array $event) use (&$events): void {
            $events[] = $event['error'];
        });

        $this->invokePrivate($worker, 'failJob', $this->claimedJob('lease-1'), '');
        $this->invokePrivate($worker, 'failJob', $this->claimedJob('lease-1'), str_repeat("\u{00e9}", 5000));

        // The event reports what was actually sent.
        $this->assertSame('Job failed', $events[0]);
        $this->assertSame($this->posts[1]['body']['error'], $events[1]);
        $this->assertSame('Job failed', $this->posts[0]['body']['error']);
        $this->assertSame(2048, mb_strlen($this->posts[1]['body']['error'], 'UTF-8'));
        $this->assertSame(1, preg_match('//u', $this->posts[1]['body']['error']));
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function a_handler_that_throws_with_no_message_still_gets_its_failure_recorded(): void
    {
        $worker = $this->createWorker();
        $worker->process(static function (): void {
            throw new RuntimeException();
        });

        $this->invokePrivate($worker, 'processJob', $this->claimedJob('lease-1'));

        $fail = array_values(array_filter($this->posts, static fn (array $p): bool => $p['path'] === 'jobs/job-123/fail'))[0];
        $this->assertSame('Job failed', $fail['body']['error']);
    }

    // --- Renewal child isolation ---

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function the_renewal_child_runs_in_its_own_process_group(): void
    {
        // A SIGTERM aimed at the worker's whole group must not kill the child
        // that keeps the draining job's lease alive.
        $worker = $this->createWorker();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        $parentGroup = posix_getpgid(posix_getpid());

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->invokePrivate($worker, 'configureRenewalChild', posix_getppid());
            fwrite($sockets[1], (string) posix_getpgid(posix_getpid()));
            fclose($sockets[1]);
            while (true) {
                usleep(100_000);
            }
        }

        fclose($sockets[1]);
        $childGroup = (int) stream_get_contents($sockets[0]);
        fclose($sockets[0]);
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        $this->assertNotSame($parentGroup, $childGroup);
        $this->assertSame($pid, $childGroup);
    }
}
