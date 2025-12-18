<?php

declare(strict_types=1);

namespace Spooled\Resources;

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
        $response = $this->httpClient->post('workflows', $params);

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
        $response = $this->httpClient->get("workflows/{$workflowId}/jobs", $params);
        $jobs = $response['jobs'] ?? $response['data'] ?? $response;

        if (!is_array($jobs) || (isset($jobs['id']))) {
            $jobs = [];
        }

        return array_map(
            fn (array $item) => WorkflowJob::fromArray($item),
            $jobs,
        );
    }

    /**
     * Get a specific job within a workflow.
     */
    public function get(string $workflowId, string $jobId): WorkflowJob
    {
        $response = $this->httpClient->get("workflows/{$workflowId}/jobs/{$jobId}");

        return WorkflowJob::fromArray($response);
    }

    /**
     * Get the status of all jobs in a workflow.
     *
     * @return array<WorkflowJobStatus>
     */
    public function getStatus(string $workflowId): array
    {
        $response = $this->httpClient->get("workflows/{$workflowId}/jobs/status");
        $statuses = $response['statuses'] ?? $response['jobs'] ?? $response;

        if (!is_array($statuses) || (isset($statuses['id']))) {
            $statuses = [];
        }

        return array_map(
            fn (array $item) => WorkflowJobStatus::fromArray($item),
            $statuses,
        );
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
     * @param array<string, mixed> $params {dependsOnJobIds: string[]}
     * @return array{success: bool, dependencies: string[]}
     */
    public function addDependencies(string $jobId, array $params): array
    {
        return $this->httpClient->post("jobs/{$jobId}/dependencies", $params);
    }
}
