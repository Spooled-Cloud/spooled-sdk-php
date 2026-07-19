<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Worker;

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
use Spooled\SpooledClient;
use Spooled\Types\ClaimedJob;
use Spooled\Version;
use Spooled\Worker\SpooledWorker;
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

                return $responder?->__invoke($path, $body) ?? ['success' => true];
            },
        );

        $effectiveLogger = $logger ?? new NullLogger();
        $clientReflection = new ReflectionClass(SpooledClient::class);
        $client = $clientReflection->newInstanceWithoutConstructor();
        $clientReflection->getProperty('jobs')->setValue($client, new JobsResource($httpClient));
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

    private function claimedJob(?string $leaseId): ClaimedJob
    {
        return new ClaimedJob(
            id: 'job-123',
            queueName: 'test-queue',
            payload: ['key' => 'value'],
            retryCount: 0,
            maxRetries: 3,
            timeoutSeconds: 300,
            leaseExpiresAt: null,
            leaseId: $leaseId,
        );
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
        $this->assertSame('1.0.21', Version::VERSION);
        $this->assertSame(Version::USER_AGENT, ClientOptions::DEFAULT_USER_AGENT);
    }
}
