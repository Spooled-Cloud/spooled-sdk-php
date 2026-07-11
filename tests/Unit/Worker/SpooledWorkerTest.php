<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Spooled\Http\HttpClient;
use Spooled\Resources\JobsResource;
use Spooled\SpooledClient;
use Spooled\Types\ClaimedJob;
use Spooled\Worker\SpooledWorker;

#[CoversClass(SpooledWorker::class)]
final class SpooledWorkerTest extends TestCase
{
    /** @var array<array{path: string, body: array<string, mixed>|null}> */
    private array $posts = [];

    /**
     * Build a worker whose jobs resource records HTTP posts into $this->posts.
     */
    private function createWorker(): SpooledWorker
    {
        $this->posts = [];

        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturnCallback(
            function (string $path, ?array $body = null): array {
                $this->posts[] = ['path' => $path, 'body' => $body];

                return ['success' => true];
            },
        );

        // SpooledClient is final with readonly properties; hydrate the two the
        // worker touches ($jobs, $logger) via reflection instead of mocking.
        $clientReflection = new ReflectionClass(SpooledClient::class);
        $client = $clientReflection->newInstanceWithoutConstructor();
        $clientReflection->getProperty('jobs')->setValue($client, new JobsResource($httpClient));
        $clientReflection->getProperty('logger')->setValue($client, new NullLogger());

        $worker = new SpooledWorker($client, ['queueName' => 'test-queue']);

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

    /**
     * @param mixed ...$args
     */
    private function invokePrivate(SpooledWorker $worker, string $method, mixed ...$args): void
    {
        (new ReflectionClass(SpooledWorker::class))
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
        // Legacy backends return no fencing token; the worker must not send one.
        $worker = $this->createWorker();

        $this->invokePrivate($worker, 'completeJob', $this->claimedJob(null), null);
        $this->invokePrivate($worker, 'failJob', $this->claimedJob(null), 'boom');

        $this->assertCount(2, $this->posts);
        $this->assertArrayNotHasKey('leaseId', $this->posts[0]['body']);
        $this->assertArrayNotHasKey('leaseId', $this->posts[1]['body']);
    }
}
