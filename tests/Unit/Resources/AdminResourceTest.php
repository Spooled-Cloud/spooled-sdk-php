<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\AdminResource;

#[CoversClass(AdminResource::class)]
final class AdminResourceTest extends TestCase
{
    #[Test]
    public function update_organization_patches_not_puts(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('patch')
            ->with(
                'admin/organizations/org_1',
                ['planTier' => 'enterprise'],
                [],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([
                'id' => 'org_1',
                'name' => 'Acme',
                'planTier' => 'enterprise',
            ]);
        $httpClient->expects($this->never())->method('put');

        $got = (new AdminResource($httpClient, 'adminkey'))
            ->updateOrganization('org_1', ['planTier' => 'enterprise']);

        $this->assertSame('org_1', $got->id);
        $this->assertSame('enterprise', $got->plan);
    }

    #[Test]
    public function delete_organization_sends_hard_delete_query(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with(
                'admin/organizations/org_1',
                ['hard_delete' => 'true'],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([]);

        $got = (new AdminResource($httpClient, 'adminkey'))
            ->deleteOrganization('org_1', true);

        $this->assertTrue($got->success);
    }

    #[Test]
    public function set_organization_limits_patches_custom_limits_not_a_missing_put_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('patch')
            ->with(
                'admin/organizations/org_1',
                ['customLimits' => ['max_jobs_per_day' => 1000]],
                [],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([
                'id' => 'org_1',
                'name' => 'Acme',
                'planTier' => 'pro',
            ]);
        $httpClient->expects($this->never())->method('put');

        $got = (new AdminResource($httpClient, 'adminkey'))
            ->setOrganizationLimits('org_1', ['max_jobs_per_day' => 1000]);

        $this->assertSame('org_1', $got->id);
    }

    #[Test]
    public function purge_queue_uses_delete_jobs_query_not_a_missing_post_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with(
                'queues/emails',
                ['delete_jobs' => 'true'],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([]);
        $httpClient->expects($this->never())->method('post');

        $got = (new AdminResource($httpClient, 'adminkey'))->purgeQueue('emails');

        $this->assertTrue($got->success);
    }

    #[Test]
    public function deregister_worker_posts_deregister_not_a_missing_delete_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'workers/w_1/deregister',
                null,
                [],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([]);
        $httpClient->expects($this->never())->method('delete');

        $got = (new AdminResource($httpClient, 'adminkey'))->deregisterWorker('w_1');

        $this->assertTrue($got->success);
    }

    #[Test]
    public function cancel_job_deletes_then_loads_the_cancelled_row(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with('jobs/job_1', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([]);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('jobs/job_1', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                'id' => 'job_1',
                'queueName' => 'emails',
                'status' => 'cancelled',
                'payload' => ['n' => 1],
            ]);
        $httpClient->expects($this->never())->method('post');

        $got = (new AdminResource($httpClient, 'adminkey'))->cancelJob('job_1');

        $this->assertSame('job_1', $got->id);
        $this->assertSame('cancelled', $got->status);
    }

    #[Test]
    public function list_jobs_gets_jobs_not_a_missing_admin_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('jobs', ['status' => 'pending'], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                [
                    'id' => 'job_1',
                    'queueName' => 'emails',
                    'status' => 'pending',
                ],
            ]);

        $got = (new AdminResource($httpClient, 'adminkey'))
            ->listJobs(['status' => 'pending']);

        $this->assertCount(1, $got->jobs);
        $this->assertSame('job_1', $got->jobs[0]->id);
    }

    #[Test]
    public function get_job_gets_jobs_id_not_a_missing_admin_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('jobs/job_1', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                'id' => 'job_1',
                'queueName' => 'emails',
                'status' => 'pending',
                'payload' => ['n' => 1],
            ]);

        $got = (new AdminResource($httpClient, 'adminkey'))->getJob('job_1');

        $this->assertSame('job_1', $got->id);
        $this->assertSame('pending', $got->status);
    }

    #[Test]
    public function list_workers_gets_workers_not_a_missing_admin_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('workers', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                [
                    'id' => 'w_1',
                    'queueName' => 'emails',
                    'status' => 'healthy',
                ],
            ]);

        $got = (new AdminResource($httpClient, 'adminkey'))->listWorkers();

        $this->assertCount(1, $got->workers);
        $this->assertSame('w_1', $got->workers[0]->id);
    }

    #[Test]
    public function get_worker_gets_workers_id_not_a_missing_admin_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('workers/w_1', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                'id' => 'w_1',
                'queueName' => 'emails',
                'status' => 'healthy',
            ]);

        $got = (new AdminResource($httpClient, 'adminkey'))->getWorker('w_1');

        $this->assertSame('w_1', $got->id);
        $this->assertSame('healthy', $got->status);
    }

    #[Test]
    public function list_queues_gets_queues_not_a_missing_admin_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('queues', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                [
                    'queueName' => 'emails',
                    'enabled' => true,
                ],
            ]);

        $got = (new AdminResource($httpClient, 'adminkey'))->listQueues();

        $this->assertCount(1, $got->queues);
        $this->assertSame('emails', $got->queues[0]->name);
    }

    #[Test]
    public function list_schedules_gets_schedules_not_a_missing_admin_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('schedules', [], ['X-Admin-Key' => 'adminkey'])
            ->willReturn([
                [
                    'id' => 'sch_1',
                    'name' => 'nightly',
                    'queueName' => 'emails',
                    'cron' => '0 0 * * *',
                ],
            ]);

        $got = (new AdminResource($httpClient, 'adminkey'))->listSchedules();

        $this->assertCount(1, $got->schedules);
        $this->assertSame('sch_1', $got->schedules[0]->id);
    }
}
