<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Resources\DlqOperations;
use Spooled\Resources\JobsResource;
use Spooled\Tests\Support\HttpClientFactory;
use Spooled\Types\JobHistoryEvent;
use Spooled\Types\JobHistoryList;
use Spooled\Types\JobList;
use Spooled\Types\JobStatus;
use Spooled\Util\ListQuery;

/**
 * Wave-8 list surface: X-Has-More paging, the new GET /jobs filters and
 * GET /jobs/{id}/history, through the real HttpClient.
 */
#[CoversClass(JobsResource::class)]
#[CoversClass(DlqOperations::class)]
#[CoversClass(JobList::class)]
#[CoversClass(JobHistoryList::class)]
#[CoversClass(JobHistoryEvent::class)]
#[CoversClass(ListQuery::class)]
final class JobListPagingTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $headers
     */
    private function rows(array $rows, array $headers = []): Response
    {
        return new Response(200, $headers + ['Content-Type' => 'application/json'], (string) json_encode($rows));
    }

    /** @return array<int, array<string, mixed>> */
    private function jobs(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['id' => "job-{$i}", 'queue_name' => 'q', 'status' => 'pending'];
        }

        return $out;
    }

    /** @return array<string, string> */
    private function lastQuery(): array
    {
        parse_str($this->history[count($this->history) - 1]['request']->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    #[Test]
    public function a_full_last_page_is_not_reported_as_having_more_when_the_server_says_so(): void
    {
        $http = HttpClientFactory::create([$this->rows($this->jobs(4), ['X-Has-More' => 'false'])], $this->history);

        $page = (new JobsResource($http))->list(['queue' => 'q', 'limit' => 4]);

        $this->assertCount(4, $page->jobs);
        $this->assertFalse($page->hasMore, 'the full-page guess said true; X-Has-More says false');
    }

    #[Test]
    public function a_short_page_has_more_when_the_server_says_so(): void
    {
        $http = HttpClientFactory::create([$this->rows($this->jobs(2), ['x-has-more' => 'true'])], $this->history);

        $page = (new JobsResource($http))->list(['limit' => 4]);

        $this->assertTrue($page->hasMore);
    }

    #[Test]
    public function without_the_header_the_full_page_guess_is_kept(): void
    {
        $http = HttpClientFactory::create([
            $this->rows($this->jobs(4)),
            $this->rows($this->jobs(3)),
        ], $this->history);
        $jobs = new JobsResource($http);

        $this->assertTrue($jobs->list(['limit' => 4])->hasMore);
        $this->assertFalse($jobs->list(['limit' => 4])->hasMore);
    }

    #[Test]
    public function the_dlq_list_reads_x_has_more_too(): void
    {
        $http = HttpClientFactory::create([$this->rows($this->jobs(2), ['X-Has-More' => 'false'])], $this->history);

        $page = (new JobsResource($http))->dlq->list(['queue' => 'q', 'limit' => 2]);

        $this->assertFalse($page->hasMore);
        $this->assertSame('/api/v1/jobs/dlq', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('q', $this->lastQuery()['queue_name']);
    }

    #[Test]
    public function list_filters_reach_the_query_string_in_the_api_form(): void
    {
        $http = HttpClientFactory::create([$this->rows([], ['X-Has-More' => 'false'])], $this->history);

        (new JobsResource($http))->list([
            'status' => ['pending', JobStatus::DEADLETTER],
            'q' => 'invoice',
            'jobType' => 'send-email',
            'createdAfter' => new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('Europe/Kyiv')),
            'createdBefore' => '2026-10-12T09:00:00Z',
            'limit' => 10,
        ]);

        $this->assertSame([
            'status' => 'pending,deadletter',
            'q' => 'invoice',
            'job_type' => 'send-email',
            'created_after' => '2026-10-12T07:00:00.000000Z',
            'created_before' => '2026-10-12T09:00:00Z',
            'limit' => '10',
        ], $this->lastQuery());
    }

    #[Test]
    public function a_single_status_is_sent_unchanged(): void
    {
        $http = HttpClientFactory::create([$this->rows([]), $this->rows([])], $this->history);
        $jobs = new JobsResource($http);

        $jobs->list(['status' => 'failed,deadletter']);
        $this->assertSame('failed,deadletter', $this->lastQuery()['status']);

        $jobs->list(['status' => JobStatus::COMPLETED]);
        $this->assertSame('completed', $this->lastQuery()['status']);
    }

    #[Test]
    public function history_reads_events_and_paging(): void
    {
        $http = HttpClientFactory::create([$this->rows([
            [
                'id' => 'evt-2',
                'job_id' => 'job-1',
                'event_type' => 'completed',
                'worker_id' => 'worker-1',
                'details' => ['duration_ms' => 12, 'nested_key' => ['keep_case' => true]],
                'created_at' => '2026-10-12T08:00:01Z',
            ],
            [
                'id' => 'evt-1',
                'job_id' => 'job-1',
                'event_type' => 'some.future_event',
                'worker_id' => null,
                'details' => [],
                'created_at' => '2026-10-12T08:00:00Z',
            ],
        ], ['X-Has-More' => 'true'])], $this->history);

        $page = (new JobsResource($http))->history('job-1', ['order' => 'desc', 'limit' => 2]);

        $this->assertSame('/api/v1/jobs/job-1/history', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['order' => 'desc', 'limit' => '2'], $this->lastQuery());
        $this->assertTrue($page->hasMore);
        $this->assertCount(2, $page->events);
        $this->assertSame('evt-2', $page->events[0]->id);
        $this->assertSame('job-1', $page->events[0]->jobId);
        $this->assertSame('completed', $page->events[0]->eventType);
        $this->assertSame('worker-1', $page->events[0]->workerId);
        $this->assertSame(['duration_ms' => 12, 'nested_key' => ['keep_case' => true]], $page->events[0]->details);
        $this->assertSame('2026-10-12T08:00:01Z', $page->events[0]->createdAt);
        $this->assertSame('some.future_event', $page->events[1]->eventType, 'unknown event types parse');
        $this->assertNull($page->events[1]->workerId);
    }

    #[Test]
    public function history_without_the_header_guesses_from_the_page_size(): void
    {
        $http = HttpClientFactory::create([$this->rows([])], $this->history);

        $page = (new JobsResource($http))->history('job-1');

        $this->assertSame([], $page->events);
        $this->assertFalse($page->hasMore);
        $this->assertSame([], $this->lastQuery());
    }
}
