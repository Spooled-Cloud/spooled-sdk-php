<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\DlqOperations;
use Spooled\Resources\JobsResource;
use Spooled\Types\CreateJobResult;
use Spooled\Types\JobList;
use Spooled\Util\FailureText;

#[CoversClass(JobsResource::class)]
#[CoversClass(DlqOperations::class)]
#[CoversClass(CreateJobResult::class)]
#[CoversClass(JobList::class)]
#[CoversClass(FailureText::class)]
final class JobsResourceTest extends TestCase
{
    #[Test]
    public function create_reads_id_and_created_not_a_full_job(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturn([
            'id' => 'job_1',
            'created' => false,
        ]);

        $got = (new JobsResource($httpClient))->create([
            'queue' => 'emails',
            'payload' => ['n' => 1],
        ]);

        $this->assertInstanceOf(CreateJobResult::class, $got);
        $this->assertSame('job_1', $got->id);
        $this->assertFalse($got->created);
    }

    #[Test]
    public function cancel_does_not_treat_204_empty_body_as_a_pending_job(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with('jobs/job_1')
            ->willReturn([]);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('jobs/job_1')
            ->willReturn([
                'id' => 'job_1',
                'queueName' => 'emails',
                'status' => 'cancelled',
                'payload' => ['n' => 1],
            ]);

        $got = (new JobsResource($httpClient))->cancel('job_1');

        $this->assertSame('job_1', $got->id);
        $this->assertSame('cancelled', $got->status);
        $this->assertSame('emails', $got->queueName);
    }

    /**
     * HttpClient mock that records every post() as [path, body, forceRetry].
     *
     * @param array<int, array{string, array<string, mixed>|null, bool}> $calls
     * @param array<string, mixed> $response
     */
    private function recordingHttpClient(array &$calls, array $response = ['success' => true]): HttpClient
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturnCallback(
            function (string $path, ?array $body = null, array $query = [], array $headers = [], bool $skipApiPrefix = false, bool $forceRetry = false) use (&$calls, $response): array {
                $calls[] = [$path, $body, $forceRetry];

                return $response;
            },
        );

        return $httpClient;
    }

    // --- SDKGP-12 / brief 4: failure text is always accepted ---

    #[Test]
    public function fail_coerces_an_empty_error_to_a_default(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls));

        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => '']);
        $jobs->fail('job_1', ['workerId' => 'w1']);
        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => "  \n"]);

        $this->assertSame('Job failed', $calls[0][1]['error']);
        $this->assertSame('Job failed', $calls[1][1]['error']);
        $this->assertSame('Job failed', $calls[2][1]['error']);
    }

    #[Test]
    public function fail_truncates_an_overlong_error_on_a_character_boundary(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls));

        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => str_repeat('x', 5000)]);
        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => str_repeat("\u{00e9}", 3000)]);
        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => str_repeat("\u{1F680}", 2100)]);

        $this->assertSame(2048, strlen($calls[0][1]['error']));
        // 2048 characters, not 2048 bytes: nothing was cut mid-sequence.
        $this->assertSame(2048, mb_strlen($calls[1][1]['error'], 'UTF-8'));
        $this->assertSame(1, preg_match('//u', $calls[1][1]['error']));
        $this->assertSame(2048, mb_strlen($calls[2][1]['error'], 'UTF-8'));
        $this->assertSame(1, preg_match('//u', $calls[2][1]['error']));
    }

    #[Test]
    public function fail_keeps_a_normal_error_untouched_and_scrubs_invalid_utf8(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls));

        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => 'upstream returned 502']);
        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => "bad \xB1 byte"]);

        $this->assertSame('upstream returned 502', $calls[0][1]['error']);
        // Invalid UTF-8 would make json_encode fail and the failure would never be recorded.
        $this->assertSame(1, preg_match('//u', $calls[1][1]['error']));
        $this->assertNotSame('', $calls[1][1]['error']);
    }

    #[Test]
    public function failure_text_helper_handles_exact_boundaries(): void
    {
        $this->assertSame(str_repeat('a', 2048), FailureText::coerce(str_repeat('a', 2048)));
        $this->assertSame(str_repeat('a', 2048), FailureText::coerce(str_repeat('a', 2049)));
        $this->assertSame('Job failed', FailureText::coerce(null));
    }

    // --- Brief 2 / SDKGP-23: acks retried, creates only with an idempotency key ---

    #[Test]
    public function acks_and_heartbeats_ask_for_retries(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls));

        $jobs->complete('job_1', ['workerId' => 'w1']);
        $jobs->fail('job_1', ['workerId' => 'w1', 'error' => 'x']);
        $jobs->heartbeat('job_1', ['workerId' => 'w1']);

        $this->assertSame(['jobs/job_1/complete', 'jobs/job_1/fail', 'jobs/job_1/heartbeat'], array_column($calls, 0));
        $this->assertSame([true, true, true], array_column($calls, 2));
    }

    #[Test]
    public function create_retries_only_when_an_idempotency_key_is_set(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls, ['id' => 'job_1', 'created' => true]));

        $jobs->create(['queue' => 'emails', 'payload' => []]);
        $jobs->create(['queue' => 'emails', 'payload' => [], 'idempotencyKey' => 'order-1']);
        $jobs->create(['queue' => 'emails', 'payload' => [], 'idempotencyKey' => '']);

        $this->assertSame([false, true, false], array_column($calls, 2));
    }

    // --- FESF-01 / FESF-02: `queue` is mapped to `queueName` ---

    #[Test]
    public function dlq_purge_and_retry_map_queue_to_queue_name(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls, ['purgedCount' => 3]));

        $jobs->dlq->purge(['queue' => 'my-queue', 'olderThan' => '2026-01-01T00:00:00Z', 'confirm' => true]);
        $jobs->dlq->retry(['queue' => 'payments', 'limit' => 50]);

        $this->assertSame('jobs/dlq/purge', $calls[0][0]);
        $this->assertSame('my-queue', $calls[0][1]['queueName']);
        $this->assertArrayNotHasKey('queue', $calls[0][1]);
        $this->assertTrue($calls[0][1]['confirm']);
        $this->assertSame('jobs/dlq/retry', $calls[1][0]);
        $this->assertSame('payments', $calls[1][1]['queueName']);
        $this->assertArrayNotHasKey('queue', $calls[1][1]);
        $this->assertSame(50, $calls[1][1]['limit']);
    }

    #[Test]
    public function dlq_list_maps_queue_and_keeps_an_explicit_queue_name(): void
    {
        $seen = [];
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('get')->willReturnCallback(function (string $path, array $query = []) use (&$seen): array {
            $seen[] = [$path, $query];

            return [];
        });

        $jobs = new JobsResource($httpClient);
        $jobs->dlq->list(['queue' => 'a']);
        $jobs->dlq->list(['queue' => 'ignored', 'queueName' => 'b']);

        $this->assertSame(['jobs/dlq', ['queueName' => 'a']], $seen[0]);
        $this->assertSame(['jobs/dlq', ['queueName' => 'b']], $seen[1]);
    }

    #[Test]
    public function bulk_enqueue_maps_queue_to_queue_name(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls, ['succeeded' => [], 'failed' => []]));

        $jobs->bulkEnqueue([
            'queue' => 'notifications',
            'jobs' => [['payload' => ['n' => 1]]],
            'defaultMaxRetries' => 5,
        ]);

        $this->assertSame('jobs/bulk', $calls[0][0]);
        $this->assertSame('notifications', $calls[0][1]['queueName']);
        $this->assertArrayNotHasKey('queue', $calls[0][1]);
        $this->assertSame(5, $calls[0][1]['defaultMaxRetries']);
    }

    #[Test]
    public function bulk_enqueue_direct_list_takes_the_queue_from_the_jobs(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls, ['succeeded' => [], 'failed' => []]));

        $jobs->bulkEnqueue([
            ['queue' => 'emails', 'payload' => ['n' => 1]],
            ['queueName' => 'emails', 'payload' => ['n' => 2]],
        ]);

        $this->assertSame('emails', $calls[0][1]['queueName']);
        $this->assertSame([['payload' => ['n' => 1]], ['payload' => ['n' => 2]]], $calls[0][1]['jobs']);
    }

    #[Test]
    public function bulk_enqueue_direct_list_without_one_queue_throws_instead_of_posting(): void
    {
        $calls = [];
        $jobs = new JobsResource($this->recordingHttpClient($calls));

        foreach ([
            [['payload' => ['n' => 1]]],
            [['queue' => 'a', 'payload' => []], ['queue' => 'b', 'payload' => []]],
        ] as $list) {
            try {
                $jobs->bulkEnqueue($list);
                $this->fail('expected an InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('queue', $e->getMessage());
            }
        }

        $this->assertSame([], $calls);
    }

    // --- SDKGP-24: hasMore ---

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => "job_{$i}", 'queue_name' => 'q', 'status' => 'pending'];
        }

        return $rows;
    }

    #[Test]
    public function has_more_is_true_for_a_full_default_page(): void
    {
        // GET /jobs with no limit returns 50 rows (the server default), not 100.
        $this->assertTrue(JobList::fromArray($this->rows(50))->hasMore);
        $this->assertFalse(JobList::fromArray($this->rows(49))->hasMore);
        $this->assertFalse(JobList::fromArray([])->hasMore);
    }

    #[Test]
    public function has_more_follows_the_requested_page_size(): void
    {
        $this->assertTrue(JobList::fromArray($this->rows(20), 20)->hasMore);
        $this->assertFalse(JobList::fromArray($this->rows(19), 20)->hasMore);
        $this->assertSame(20, JobList::fromArray($this->rows(5), 20)->pageSize);
        // The API clamps the page to 100 rows.
        $this->assertTrue(JobList::fromArray($this->rows(100), 500)->hasMore);
        $this->assertFalse(JobList::fromArray($this->rows(99), 500)->hasMore);
    }

    #[Test]
    public function list_remembers_the_requested_limit(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('get')->willReturnOnConsecutiveCalls($this->rows(20), $this->rows(20), $this->rows(7));

        $jobs = new JobsResource($httpClient);

        $this->assertTrue($jobs->list(['limit' => 20])->hasMore);
        $this->assertTrue($jobs->dlq->list(['limit' => 20])->hasMore);
        $this->assertFalse($jobs->list(['limit' => 20])->hasMore);
    }
}
