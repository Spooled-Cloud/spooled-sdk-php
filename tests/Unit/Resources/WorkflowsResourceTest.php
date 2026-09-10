<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\WorkflowJobsSubResource;
use Spooled\Resources\WorkflowsResource;
use Spooled\Util\Casing;

#[CoversClass(WorkflowsResource::class)]
#[CoversClass(WorkflowJobsSubResource::class)]
final class WorkflowsResourceTest extends TestCase
{
    #[Test]
    public function create_maps_job_queue_alias_to_queue_name(): void
    {
        $captured = null;
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args[1] ?? null;

                return [
                    'workflowId' => 'wf_1',
                    'jobIds' => [
                        ['key' => 'extract', 'jobId' => 'job_1'],
                        ['key' => 'transform', 'jobId' => 'job_2'],
                    ],
                    'status' => 'pending',
                ];
            },
        );

        $workflow = (new WorkflowsResource($httpClient))->create([
            'name' => 'ETL Pipeline',
            'jobs' => [
                ['key' => 'extract', 'queue' => 'etl', 'payload' => ['step' => 'extract']],
                [
                    'key' => 'transform',
                    'queue' => 'etl',
                    'payload' => ['step' => 'transform'],
                    'dependsOn' => ['extract'],
                ],
            ],
        ]);

        $this->assertIsArray($captured);
        $body = Casing::keysToSnakeCase($captured);

        $this->assertSame('etl', $body['jobs'][0]['queue_name']);
        $this->assertArrayNotHasKey('queue', $body['jobs'][0]);
        $this->assertSame(['extract'], $body['jobs'][1]['depends_on']);
        $this->assertSame('ETL Pipeline', $workflow->name);
        $this->assertSame(2, $workflow->totalJobs);
        $this->assertSame('wf_1', $workflow->id);
    }

    #[Test]
    public function get_maps_progress_counts_from_workflow_detail(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('workflows/wf_1')
            ->willReturn($this->detailPayload());

        $workflow = (new WorkflowsResource($httpClient))->get('wf_1');

        $this->assertSame('wf_1', $workflow->id);
        $this->assertSame('ETL', $workflow->name);
        $this->assertSame(2, $workflow->totalJobs);
        $this->assertSame(1, $workflow->completedJobs);
        $this->assertSame(0, $workflow->failedJobs);
    }

    #[Test]
    public function list_jobs_reads_workflow_detail_not_a_jobs_subpath(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('workflows/wf_1')
            ->willReturn($this->detailPayload());

        $jobs = (new WorkflowsResource($httpClient))->jobs->list('wf_1');

        $this->assertCount(2, $jobs);
        $this->assertSame('etl', $jobs[0]->queueName);
        $this->assertSame(['job_1'], $jobs[1]->dependsOn);
        $this->assertSame('job_2', $jobs[1]->id);
    }

    #[Test]
    public function list_jobs_keeps_non_object_payload_and_maps_attempt_and_error_message(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('get')->willReturn([
            'id' => 'wf_1',
            'name' => 'ETL',
            'status' => 'failed',
            'progress' => ['total' => 1, 'completed' => 0, 'failed' => 1, 'pending' => 0, 'processing' => 0],
            'jobs' => [
                [
                    'id' => 'job_1',
                    'queue' => 'etl',
                    'payload' => 'plain-string',
                    'result' => true,
                    'status' => 'failed',
                    'attempt' => 2,
                    'maxRetries' => 3,
                    'error' => ['type' => 'runtime', 'message' => 'boom'],
                    'workflowId' => 'wf_1',
                ],
            ],
            'dependencies' => [],
        ]);

        $jobs = (new WorkflowsResource($httpClient))->jobs->list('wf_1');

        $this->assertCount(1, $jobs);
        $this->assertSame('plain-string', $jobs[0]->payload);
        $this->assertTrue($jobs[0]->result);
        $this->assertSame(2, $jobs[0]->retryCount);
        $this->assertSame('boom', $jobs[0]->error);
    }

    #[Test]
    public function get_dependencies_maps_backend_shape(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('jobs/job_2/dependencies')
            ->willReturn([
                'jobId' => 'job_2',
                'dependencies' => [
                    ['jobId' => 'job_1', 'queueName' => 'etl', 'status' => 'completed'],
                ],
                'dependents' => [
                    ['jobId' => 'job_3', 'queueName' => 'etl', 'status' => 'pending'],
                ],
                'dependenciesMet' => true,
            ]);

        $got = (new WorkflowsResource($httpClient))->jobs->getDependencies('job_2');

        $this->assertSame('job_2', $got->jobId);
        $this->assertTrue($got->dependenciesMet);
        $this->assertCount(1, $got->dependencies);
        $this->assertSame('job_1', $got->dependencies[0]->jobId);
        $this->assertSame('etl', $got->dependencies[0]->queueName);
        $this->assertTrue($got->dependencies[0]->isMet);
        $this->assertCount(1, $got->dependents);
        $this->assertSame('job_3', $got->dependents[0]->jobId);
        $this->assertFalse($got->dependents[0]->isMet);
    }

    #[Test]
    public function delete_posts_cancel_not_a_missing_delete_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('workflows/wf_1/cancel')
            ->willReturn([
                'id' => 'wf_1',
                'name' => 'ETL',
                'status' => 'cancelled',
            ]);
        $httpClient->expects($this->never())->method('delete');

        $got = (new WorkflowsResource($httpClient))->delete('wf_1');

        $this->assertTrue($got->success);
    }

    #[Test]
    public function add_dependencies_sends_depends_on_not_depends_on_job_ids(): void
    {
        $captured = null;
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args[1] ?? null;

                return ['dependenciesAdded' => 1, 'dependenciesMet' => false];
            },
        );

        $result = (new WorkflowsResource($httpClient))->jobs->addDependencies('job_2', [
            'dependsOnJobIds' => ['job_1'],
        ]);

        $this->assertIsArray($captured);
        $this->assertSame(['job_1'], $captured['dependsOn']);
        $this->assertArrayNotHasKey('dependsOnJobIds', $captured);
        $this->assertSame(1, $result['added']);
        $this->assertFalse($result['dependenciesMet']);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(): array
    {
        return [
            'id' => 'wf_1',
            'name' => 'ETL',
            'status' => 'running',
            'progress' => [
                'total' => 2,
                'completed' => 1,
                'failed' => 0,
                'pending' => 1,
                'processing' => 0,
            ],
            'jobs' => [
                [
                    'id' => 'job_1',
                    'queue' => 'etl',
                    'payload' => ['step' => 'extract'],
                    'status' => 'completed',
                    'priority' => 0,
                    'attempt' => 1,
                    'maxRetries' => 3,
                    'createdAt' => '2024-01-01T00:00:00Z',
                    'workflowId' => 'wf_1',
                ],
                [
                    'id' => 'job_2',
                    'queue' => 'etl',
                    'payload' => ['step' => 'transform'],
                    'status' => 'pending',
                    'priority' => 0,
                    'attempt' => 0,
                    'maxRetries' => 3,
                    'createdAt' => '2024-01-01T00:00:00Z',
                    'workflowId' => 'wf_1',
                ],
            ],
            'dependencies' => [
                [
                    'parentJobId' => 'job_1',
                    'childJobId' => 'job_2',
                    'dependencyType' => 'all',
                ],
            ],
        ];
    }
}
