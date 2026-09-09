<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Errors\NotFoundError;
use Spooled\Http\HttpClient;
use Spooled\Types\JobWithDependencies;
use Spooled\Types\SuccessResponse;
use Spooled\Types\Workflow;
use Spooled\Types\WorkflowJob;
use Spooled\Types\WorkflowJobStatus;
use Spooled\Types\WorkflowList;

/**
 * Workflows resource for managing workflows.
 */
final class WorkflowsResource extends BaseResource
{
    /** Workflow jobs sub-resource */
    public readonly WorkflowJobsSubResource $jobs;

    public function __construct(HttpClient $httpClient)
    {
        parent::__construct($httpClient);
        $this->jobs = new WorkflowJobsSubResource($httpClient);
    }

    /**
     * List all workflows.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): WorkflowList
    {
        $response = $this->httpClient->get('workflows', $params);

        return WorkflowList::fromArray($response);
    }

    /**
     * Create a new workflow.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params): Workflow
    {
        $params = $this->mapWriteParams($params);

        $response = $this->httpClient->post('workflows', $params);
        $response = $this->applyCreateFallbacks($response, $params);

        return Workflow::fromArray($response);
    }

    /**
     * Get a workflow by ID.
     */
    public function get(string $workflowId): Workflow
    {
        $response = $this->httpClient->get("workflows/{$workflowId}");

        return Workflow::fromArray($response);
    }

    /**
     * Cancel a workflow.
     */
    public function cancel(string $workflowId): Workflow
    {
        $response = $this->httpClient->post("workflows/{$workflowId}/cancel");

        return Workflow::fromArray($response);
    }

    /**
     * Retry a failed workflow.
     *
     * Resets all failed/deadletter jobs back to pending and resumes the workflow.
     * Only workflows with status 'failed' can be retried.
     */
    public function retry(string $workflowId): Workflow
    {
        $response = $this->httpClient->post("workflows/{$workflowId}/retry");

        return Workflow::fromArray($response);
    }

    /**
     * Delete a workflow.
     */
    public function delete(string $workflowId): SuccessResponse
    {
        $response = $this->httpClient->delete("workflows/{$workflowId}");

        return SuccessResponse::fromArray($response);
    }

    /**
     * Map documented job aliases onto the names POST /workflows expects.
     * The HTTP layer snake-cases camelCase keys (queueName -> queue_name).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mapWriteParams(array $params): array
    {
        if (!isset($params['jobs']) || !is_array($params['jobs'])) {
            return $params;
        }

        foreach ($params['jobs'] as $i => $job) {
            if (!is_array($job)) {
                continue;
            }
            if (isset($job['queue']) && !isset($job['queueName']) && !isset($job['queue_name'])) {
                $job['queueName'] = $job['queue'];
                unset($job['queue']);
            }
            if (isset($job['name']) && !isset($job['key'])) {
                $job['key'] = $job['name'];
                unset($job['name']);
            }
            if (isset($job['dependencies']) && !isset($job['dependsOn']) && !isset($job['depends_on'])) {
                $job['dependsOn'] = $job['dependencies'];
                unset($job['dependencies']);
            }
            $params['jobs'][$i] = $job;
        }

        return $params;
    }

    /**
     * POST /workflows returns {workflowId, jobIds, status} and never echoes
     * name or totalJobs. Backfill from the request so create() matches get().
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function applyCreateFallbacks(array $response, array $params): array
    {
        foreach (['name', 'description', 'metadata'] as $key) {
            if (!array_key_exists($key, $response) && array_key_exists($key, $params)) {
                $response[$key] = $params[$key];
            }
        }

        if (!isset($response['totalJobs']) && !isset($response['total_jobs'])) {
            $jobIds = $response['jobIds'] ?? $response['job_ids'] ?? null;
            $jobs = $params['jobs'] ?? null;
            $response['totalJobs'] = is_array($jobIds)
                ? count($jobIds)
                : (is_array($jobs) ? count($jobs) : 0);
        }

        return $response;
    }
}

/**
 * Sub-resource for workflow jobs operations.
 */
final class WorkflowJobsSubResource extends BaseResource
{
    /**
     * List all jobs in a workflow.
     *
     * @param array<string, mixed> $params
     * @return array<WorkflowJob>
     */
    public function list(string $workflowId, array $params = []): array
    {
        $response = $this->httpClient->get("workflows/{$workflowId}");

        return $this->jobsFromDetail($response);
    }

    /**
     * Get a specific job within a workflow.
     */
    public function get(string $workflowId, string $jobId): WorkflowJob
    {
        foreach ($this->list($workflowId) as $job) {
            if ($job->id === $jobId) {
                return $job;
            }
        }

        throw new NotFoundError("Job {$jobId} not found in workflow {$workflowId}");
    }

    /**
     * Get the status of all jobs in a workflow.
     *
     * @return array<WorkflowJobStatus>
     */
    public function getStatus(string $workflowId): array
    {
        $statuses = [];
        foreach ($this->list($workflowId) as $job) {
            $statuses[] = new WorkflowJobStatus(
                jobId: $job->id,
                key: $job->key,
                status: $job->status,
                retryCount: $job->retryCount,
                error: $job->error,
            );
        }

        return $statuses;
    }

    /**
     * Get job dependencies.
     *
     * Note: This queries the jobs endpoint, not workflows endpoint.
     */
    public function getDependencies(string $jobId): JobWithDependencies
    {
        $response = $this->httpClient->get("jobs/{$jobId}/dependencies");

        return JobWithDependencies::fromArray($response);
    }

    /**
     * Add dependencies to a job.
     *
     * @param array<string, mixed> $params {dependsOnJobIds?: string[], dependsOn?: string[], dependencyMode?: string}
     * @return array{success: bool, added: int, dependenciesMet: bool, dependencies: mixed}
     */
    public function addDependencies(string $jobId, array $params): array
    {
        $ids = $params['dependsOn'] ?? $params['dependsOnJobIds'] ?? $params['depends_on'] ?? [];
        $mode = $params['dependencyMode'] ?? $params['dependency_mode'] ?? null;
        $type = $params['dependencyType'] ?? $params['dependency_type'] ?? null;
        if ($mode === null && ($type === 'all' || $type === 'any')) {
            $mode = $type;
        }

        $body = ['dependsOn' => $ids];
        if ($mode !== null) {
            $body['dependencyMode'] = $mode;
        }

        $response = $this->httpClient->post("jobs/{$jobId}/dependencies", $body);
        $added = (int) ($response['dependenciesAdded'] ?? $response['added'] ?? 0);

        return [
            'success' => $added > 0,
            'added' => $added,
            'dependenciesMet' => (bool) ($response['dependenciesMet'] ?? false),
            'dependencies' => $response['dependencies'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<WorkflowJob>
     */
    private function jobsFromDetail(array $detail): array
    {
        $jobs = $detail['jobs'] ?? [];
        if (!is_array($jobs) || $jobs === [] || isset($jobs['id'])) {
            return [];
        }

        $deps = is_array($detail['dependencies'] ?? null) ? $detail['dependencies'] : [];
        $workflowId = (string) ($detail['id'] ?? '');
        $mapped = [];

        foreach ($jobs as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            $dependsOn = [];
            foreach ($deps as $edge) {
                if (is_array($edge) && (string) ($edge['childJobId'] ?? '') === $id) {
                    $dependsOn[] = (string) ($edge['parentJobId'] ?? '');
                }
            }
            $item['dependsOn'] = $dependsOn;
            $item['workflowId'] = $item['workflowId'] ?? $workflowId;
            if (isset($item['error']) && is_array($item['error'])) {
                $item['error'] = (string) ($item['error']['message'] ?? '');
            }
            if (isset($item['attempt']) && !isset($item['retryCount'])) {
                $item['retryCount'] = $item['attempt'];
            }
            $mapped[] = WorkflowJob::fromArray($item);
        }

        return $mapped;
    }
}
