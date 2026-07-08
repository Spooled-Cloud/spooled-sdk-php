<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\ApiKeyList;
use Spooled\Types\OrganizationList;
use Spooled\Types\QueueList;
use Spooled\Types\ScheduleList;
use Spooled\Types\WebhookDeliveryList;
use Spooled\Types\WebhookList;
use Spooled\Types\WorkerList;

/**
 * Regression tests for list endpoints that return a BARE top-level JSON array
 * (e.g. GET /api/v1/schedules -> [ {...}, {...} ]) rather than a wrapped object.
 *
 * Previously these *List::fromArray() methods only read the wrapped keys
 * (`schedules`, `queues`, ...), so a bare array parsed to an empty list and the
 * items were silently invisible to SDK users.
 */
#[CoversClass(ScheduleList::class)]
#[CoversClass(ApiKeyList::class)]
#[CoversClass(QueueList::class)]
#[CoversClass(WorkerList::class)]
#[CoversClass(WebhookList::class)]
#[CoversClass(WebhookDeliveryList::class)]
#[CoversClass(OrganizationList::class)]
final class BareArrayListTest extends TestCase
{
    // --- ScheduleList (the reported production bug) ---

    #[Test]
    public function schedule_list_parses_bare_top_level_array(): void
    {
        // The exact shape GET /api/v1/schedules returns in production.
        $data = [
            ['id' => 'sch-1', 'name' => 'nightly', 'queue_name' => 'reports', 'cron_expression' => '0 0 * * *'],
            ['id' => 'sch-2', 'name' => 'hourly', 'queue_name' => 'sync', 'cron_expression' => '0 * * * *'],
        ];

        $list = ScheduleList::fromArray($data);

        $this->assertCount(2, $list->schedules);
        $this->assertSame('sch-1', $list->schedules[0]->id);
        $this->assertSame('reports', $list->schedules[0]->queue);
        $this->assertSame('sch-2', $list->schedules[1]->id);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function schedule_list_parses_wrapped_object(): void
    {
        $data = [
            'schedules' => [
                ['id' => 'sch-1', 'name' => 'nightly', 'queue_name' => 'reports'],
            ],
            'total' => 42,
            'page' => 2,
            'pageSize' => 10,
        ];

        $list = ScheduleList::fromArray($data);

        $this->assertCount(1, $list->schedules);
        $this->assertSame('sch-1', $list->schedules[0]->id);
        $this->assertSame(42, $list->total);
        $this->assertSame(2, $list->page);
        $this->assertSame(10, $list->pageSize);
    }

    #[Test]
    public function schedule_list_parses_empty_array(): void
    {
        $list = ScheduleList::fromArray([]);

        $this->assertCount(0, $list->schedules);
        $this->assertSame(0, $list->total);
    }

    // --- ApiKeyList ---

    #[Test]
    public function api_key_list_parses_bare_top_level_array(): void
    {
        $data = [
            ['id' => 'key-1', 'name' => 'ci', 'prefix' => 'sp_ci'],
            ['id' => 'key-2', 'name' => 'prod', 'prefix' => 'sp_pr'],
        ];

        $list = ApiKeyList::fromArray($data);

        $this->assertCount(2, $list->apiKeys);
        $this->assertSame('key-1', $list->apiKeys[0]->id);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function api_key_list_parses_wrapped_object(): void
    {
        $data = [
            'apiKeys' => [['id' => 'key-1', 'name' => 'ci', 'prefix' => 'sp_ci']],
            'total' => 7,
        ];

        $list = ApiKeyList::fromArray($data);

        $this->assertCount(1, $list->apiKeys);
        $this->assertSame('key-1', $list->apiKeys[0]->id);
        $this->assertSame(7, $list->total);
    }

    // --- QueueList ---

    #[Test]
    public function queue_list_parses_bare_top_level_array(): void
    {
        $data = [
            ['name' => 'default'],
            ['name' => 'emails'],
        ];

        $list = QueueList::fromArray($data);

        $this->assertCount(2, $list->queues);
        $this->assertSame('default', $list->queues[0]->name);
        $this->assertSame('emails', $list->queues[1]->name);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function queue_list_parses_wrapped_object(): void
    {
        $data = [
            'queues' => [['name' => 'default']],
            'total' => 3,
        ];

        $list = QueueList::fromArray($data);

        $this->assertCount(1, $list->queues);
        $this->assertSame('default', $list->queues[0]->name);
        $this->assertSame(3, $list->total);
    }

    // --- WorkerList ---

    #[Test]
    public function worker_list_parses_bare_top_level_array(): void
    {
        $data = [
            ['id' => 'wrk-1', 'name' => 'worker-a'],
            ['id' => 'wrk-2', 'name' => 'worker-b'],
        ];

        $list = WorkerList::fromArray($data);

        $this->assertCount(2, $list->workers);
        $this->assertSame('wrk-1', $list->workers[0]->id);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function worker_list_parses_wrapped_object(): void
    {
        $data = [
            'workers' => [['id' => 'wrk-1', 'name' => 'worker-a']],
            'total' => 5,
        ];

        $list = WorkerList::fromArray($data);

        $this->assertCount(1, $list->workers);
        $this->assertSame('wrk-1', $list->workers[0]->id);
        $this->assertSame(5, $list->total);
    }

    // --- WebhookList ---

    #[Test]
    public function webhook_list_parses_bare_top_level_array(): void
    {
        $data = [
            ['id' => 'wh-1', 'name' => 'slack', 'url' => 'https://example.com/1'],
            ['id' => 'wh-2', 'name' => 'pager', 'url' => 'https://example.com/2'],
        ];

        $list = WebhookList::fromArray($data);

        $this->assertCount(2, $list->webhooks);
        $this->assertSame('wh-1', $list->webhooks[0]->id);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function webhook_list_parses_wrapped_object(): void
    {
        $data = [
            'webhooks' => [['id' => 'wh-1', 'name' => 'slack', 'url' => 'https://example.com/1']],
            'total' => 9,
        ];

        $list = WebhookList::fromArray($data);

        $this->assertCount(1, $list->webhooks);
        $this->assertSame('wh-1', $list->webhooks[0]->id);
        $this->assertSame(9, $list->total);
    }

    // --- WebhookDeliveryList ---

    #[Test]
    public function webhook_delivery_list_parses_bare_top_level_array(): void
    {
        $data = [
            ['id' => 'del-1', 'webhookId' => 'wh-1', 'eventType' => 'job.completed'],
            ['id' => 'del-2', 'webhookId' => 'wh-1', 'eventType' => 'job.failed'],
        ];

        $list = WebhookDeliveryList::fromArray($data);

        $this->assertCount(2, $list->deliveries);
        $this->assertSame('del-1', $list->deliveries[0]->id);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function webhook_delivery_list_parses_wrapped_object(): void
    {
        $data = [
            'deliveries' => [['id' => 'del-1', 'webhookId' => 'wh-1', 'eventType' => 'job.completed']],
            'total' => 12,
            'page' => 1,
            'pageSize' => 25,
        ];

        $list = WebhookDeliveryList::fromArray($data);

        $this->assertCount(1, $list->deliveries);
        $this->assertSame('del-1', $list->deliveries[0]->id);
        $this->assertSame(12, $list->total);
        $this->assertSame(25, $list->pageSize);
    }

    // --- OrganizationList ---

    #[Test]
    public function organization_list_parses_bare_top_level_array(): void
    {
        $data = [
            ['id' => 'org-1', 'name' => 'Acme', 'slug' => 'acme'],
            ['id' => 'org-2', 'name' => 'Globex', 'slug' => 'globex'],
        ];

        $list = OrganizationList::fromArray($data);

        $this->assertCount(2, $list->organizations);
        $this->assertSame('org-1', $list->organizations[0]->id);
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function organization_list_parses_wrapped_object(): void
    {
        $data = [
            'organizations' => [['id' => 'org-1', 'name' => 'Acme', 'slug' => 'acme']],
            'total' => 1,
        ];

        $list = OrganizationList::fromArray($data);

        $this->assertCount(1, $list->organizations);
        $this->assertSame('org-1', $list->organizations[0]->id);
        $this->assertSame(1, $list->total);
    }
}
