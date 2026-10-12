<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Resources\WebhookIngestionResource;
use Spooled\Resources\WebhooksResource;
use Spooled\Tests\Support\HttpClientFactory;
use Spooled\Types\BillingStatus;
use Spooled\Types\InboundWebhookDelivery;
use Spooled\Types\InboundWebhookDeliveryList;
use Spooled\Types\Job;
use Spooled\Types\JobHistoryEvent;
use Spooled\Types\Organization;
use Spooled\Types\OrganizationUsage;
use Spooled\Types\QueueStats;
use Spooled\Types\Webhook;
use Spooled\Types\WebhookDelivery;
use Spooled\Types\WebhookEvent;
use Spooled\Types\WebhookFormat;
use Spooled\Types\Worker;
use Spooled\Types\WorkerList;
use Spooled\Types\Workflow;

/**
 * The wave-8 API additions as typed SDK surface, and tolerance of values the
 * server may add later.
 */
#[CoversClass(Worker::class)]
#[CoversClass(Webhook::class)]
#[CoversClass(QueueStats::class)]
#[CoversClass(InboundWebhookDelivery::class)]
#[CoversClass(InboundWebhookDeliveryList::class)]
#[CoversClass(WebhookIngestionResource::class)]
#[CoversClass(WebhooksResource::class)]
final class Wave8SurfaceTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    private function json(array $body, array $headers = []): Response
    {
        return new Response(200, $headers + ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    #[Test]
    public function worker_detail_and_list_carry_the_job_counters(): void
    {
        $worker = Worker::fromArray(['id' => 'w1', 'status' => 'healthy', 'jobsProcessed' => 3, 'jobsFailed' => 2]);
        $this->assertSame(3, $worker->jobsProcessed);
        $this->assertSame(2, $worker->jobsFailed);

        $list = WorkerList::fromArray([['id' => 'w1', 'jobs_processed' => 7, 'jobs_failed' => 0]]);
        $this->assertSame(7, $list->workers[0]->jobsProcessed);
        $this->assertSame(0, $list->workers[0]->jobsFailed);

        $registered = Worker::fromArray(['id' => 'w2', 'leaseDurationSecs' => 30]);
        $this->assertNull($registered->jobsProcessed, 'registration responses carry no counters');
        $this->assertNull($registered->jobsFailed);
    }

    #[Test]
    public function webhook_reads_format_and_keeps_unknown_events_and_formats(): void
    {
        $webhook = Webhook::fromArray([
            'id' => 'wh1',
            'events' => ['job.deadlettered', 'job.retried', 'workflow.completed', 'workflow.failed', 'job.some_future_event'],
            'format' => 'slack',
        ]);
        $this->assertSame('slack', $webhook->format);
        $this->assertSame(
            ['job.deadlettered', 'job.retried', 'workflow.completed', 'workflow.failed', 'job.some_future_event'],
            $webhook->events,
        );

        $this->assertSame('carrier-pigeon', Webhook::fromArray(['id' => 'wh2', 'format' => 'carrier-pigeon'])->format);
        $this->assertNull(Webhook::fromArray(['id' => 'wh3'])->format, 'servers before 0.1.122 send no format');
    }

    #[Test]
    public function webhook_create_and_update_send_enum_cases_as_strings(): void
    {
        $echo = [
            'id' => 'wh1',
            'name' => 'n',
            'url' => 'https://example.com/hook',
            'events' => ['job.deadlettered', 'workflow.failed'],
            'format' => 'discord',
            'enabled' => true,
        ];
        $http = HttpClientFactory::create([$this->json($echo), $this->json(['format' => 'teams'] + $echo)], $this->history);
        $webhooks = new WebhooksResource($http);

        $created = $webhooks->create([
            'name' => 'n',
            'url' => 'https://example.com/hook',
            'events' => [WebhookEvent::JOB_DEADLETTERED, 'workflow.failed'],
            'format' => WebhookFormat::DISCORD,
        ]);
        $updated = $webhooks->update('wh1', ['format' => WebhookFormat::TEAMS, 'events' => [WebhookEvent::JOB_RETRIED]]);

        $this->assertSame(
            ['name' => 'n', 'url' => 'https://example.com/hook', 'events' => ['job.deadlettered', 'workflow.failed'], 'format' => 'discord'],
            json_decode((string) $this->history[0]['request']->getBody(), true),
        );
        $this->assertSame(
            ['format' => 'teams', 'events' => ['job.retried']],
            json_decode((string) $this->history[1]['request']->getBody(), true),
        );
        $this->assertSame('discord', $created->format);
        $this->assertSame('teams', $updated->format);
    }

    #[Test]
    public function webhook_event_and_format_cover_what_the_api_accepts(): void
    {
        $this->assertCount(14, WebhookEvent::cases());
        foreach (['job.deadlettered', 'job.retried', 'workflow.completed', 'workflow.failed'] as $name) {
            $this->assertNotNull(WebhookEvent::tryFrom($name), $name);
        }
        $this->assertSame(['json', 'slack', 'discord', 'teams'], array_map(
            static fn (WebhookFormat $f): string => $f->value,
            WebhookFormat::cases(),
        ));
    }

    #[Test]
    public function queue_stats_map_the_dead_letter_count_and_oldest_job_age(): void
    {
        $stats = QueueStats::fromArray([
            'queueName' => 'q',
            'pendingJobs' => 2,
            'processingJobs' => 1,
            'completedJobs24h' => 5,
            'failedJobs24h' => 1,
            'deadletterJobs' => 4,
            'avgProcessingTimeMs' => 12.5,
            'maxJobAgeSeconds' => 90,
            'activeWorkers' => 1,
        ]);

        $this->assertSame(4, $stats->deadletter);
        $this->assertSame(90, $stats->maxJobAgeSeconds);
        $this->assertSame(2, $stats->pending);
        $this->assertSame(0, $stats->total, 'the API sends no total');
        $this->assertNull(QueueStats::fromArray(['queueName' => 'q', 'maxJobAgeSeconds' => null])->maxJobAgeSeconds);
    }

    #[Test]
    public function inbound_deliveries_list_sends_filters_and_reads_rows(): void
    {
        $http = HttpClientFactory::create([$this->json([
            [
                'id' => 'd1',
                'provider' => 'custom',
                'event_type' => 'zz.inbound',
                'matched_queue' => 'emails',
                'job_id' => 'job-1',
                'status' => 'created',
                'created_at' => '2026-10-12T08:00:00Z',
            ],
            [
                'id' => 'd0',
                'provider' => 'custom',
                'event_type' => 'old',
                'matched_queue' => null,
                'job_id' => null,
                'status' => null,
                'created_at' => '2026-10-01T08:00:00Z',
            ],
        ])], $this->history);

        $page = (new WebhookIngestionResource($http))->listDeliveries([
            'status' => 'created',
            'since' => new DateTimeImmutable('2026-10-12 08:00:00', new DateTimeZone('UTC')),
            'queue' => 'emails',
            'limit' => 2,
        ]);

        $request = $this->history[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/webhooks/deliveries', $request->getUri()->getPath());
        $this->assertSame([
            'status' => 'created',
            'since' => '2026-10-12T08:00:00.000000Z',
            'limit' => '2',
            'queue_name' => 'emails',
        ], $query);

        $this->assertCount(2, $page->deliveries);
        $this->assertTrue($page->hasMore, 'a full page without X-Has-More may have more');
        $first = $page->deliveries[0];
        $this->assertSame('d1', $first->id);
        $this->assertSame('custom', $first->provider);
        $this->assertSame('zz.inbound', $first->eventType);
        $this->assertSame('emails', $first->matchedQueue);
        $this->assertSame('job-1', $first->jobId);
        $this->assertSame('created', $first->status);
        $this->assertSame('2026-10-12T08:00:00Z', $first->createdAt);
        $this->assertNull($page->deliveries[1]->jobId);
        $this->assertNull($page->deliveries[1]->status);
    }

    #[Test]
    public function new_enum_values_from_the_server_never_fail_parsing(): void
    {
        $this->assertSame('paused', Job::fromArray(['id' => 'j', 'status' => 'paused'])->status);
        $this->assertSame('rebalancing', Worker::fromArray(['id' => 'w', 'status' => 'rebalancing'])->status);
        $this->assertSame('suspended', Workflow::fromArray(['id' => 'wf', 'status' => 'suspended'])->status);
        $this->assertSame('platinum', Organization::fromArray(['id' => 'o', 'planTier' => 'platinum'])->plan);
        $this->assertSame('platinum', OrganizationUsage::fromArray(['plan' => 'platinum'])->plan);
        $this->assertSame('platinum', BillingStatus::fromArray(['plan_tier' => 'platinum'])->planTier);
        $this->assertSame('throttled', WebhookDelivery::fromArray(['id' => 'd', 'status' => 'throttled', 'event' => 'job.someday'])->status);
        $this->assertSame('job.someday', WebhookDelivery::fromArray(['id' => 'd', 'event' => 'job.someday'])->eventType);
        $this->assertSame('lease.stolen', JobHistoryEvent::fromArray(['id' => 'e', 'eventType' => 'lease.stolen'])->eventType);
        $this->assertSame('replayed', InboundWebhookDelivery::fromArray(['id' => 'd', 'status' => 'replayed'])->status);
    }
}
