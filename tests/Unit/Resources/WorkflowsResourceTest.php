<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\WorkflowJobsSubResource;
use Spooled\Resources\WorkflowsResource;

#[CoversClass(WorkflowJobsSubResource::class)]
final class WorkflowsResourceTest extends TestCase
{
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
