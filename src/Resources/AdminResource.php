<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Http\HttpClient;
use Spooled\Types\Job;
use Spooled\Types\JobList;
use Spooled\Types\Organization;
use Spooled\Types\OrganizationList;
use Spooled\Types\QueueList;
use Spooled\Types\ScheduleList;
use Spooled\Types\SuccessResponse;
use Spooled\Types\Worker;
use Spooled\Types\WorkerList;
use Spooled\Types\WorkflowList;

/**
 * Admin resource for administrative operations.
 */
final class AdminResource extends BaseResource
{
    private ?string $adminKey;

    public function __construct(HttpClient $httpClient, ?string $adminKey = null)
    {
        parent::__construct($httpClient);
        $this->adminKey = $adminKey;
    }

    /**
     * List all organizations (admin).
     *
     * @param array<string, mixed> $params
     */
    public function listOrganizations(array $params = []): OrganizationList
    {
        $response = $this->httpClient->get('admin/organizations', $params, $this->getAdminHeaders());

        return OrganizationList::fromArray($response);
    }

    /**
     * Get an organization (admin).
     */
    public function getOrganization(string $orgId): Organization
    {
        $response = $this->httpClient->get("admin/organizations/{$orgId}", [], $this->getAdminHeaders());

        return Organization::fromArray($response);
    }

    /**
     * Update an organization (admin).
     *
     * @param array<string, mixed> $params
     */
    public function updateOrganization(string $orgId, array $params): Organization
    {
        $response = $this->httpClient->put("admin/organizations/{$orgId}", $params, [], $this->getAdminHeaders());

        return Organization::fromArray($response);
    }

    /**
     * Delete an organization (admin).
     */
    public function deleteOrganization(string $orgId): SuccessResponse
    {
        $response = $this->httpClient->delete("admin/organizations/{$orgId}", [], $this->getAdminHeaders());

        return SuccessResponse::fromArray($response);
    }

    /**
     * List all jobs (admin).
     *
     * @param array<string, mixed> $params
     */
    public function listJobs(array $params = []): JobList
    {
        $response = $this->httpClient->get('admin/jobs', $params, $this->getAdminHeaders());

        return JobList::fromArray($response);
    }

    /**
     * Get a job (admin).
     */
    public function getJob(string $jobId): Job
    {
        $response = $this->httpClient->get("admin/jobs/{$jobId}", [], $this->getAdminHeaders());

        return Job::fromArray($response);
    }

    /**
     * Cancel a job (admin).
     */
    public function cancelJob(string $jobId): Job
    {
        $response = $this->httpClient->post("admin/jobs/{$jobId}/cancel", null, [], $this->getAdminHeaders());

        return Job::fromArray($response);
    }

    /**
     * List all workers (admin).
     *
     * @param array<string, mixed> $params
     */
    public function listWorkers(array $params = []): WorkerList
    {
        $response = $this->httpClient->get('admin/workers', $params, $this->getAdminHeaders());

        return WorkerList::fromArray($response);
    }

    /**
     * Get a worker (admin).
     */
    public function getWorker(string $workerId): Worker
    {
        $response = $this->httpClient->get("admin/workers/{$workerId}", [], $this->getAdminHeaders());

        return Worker::fromArray($response);
    }

    /**
     * Deregister a worker (admin).
     */
    public function deregisterWorker(string $workerId): SuccessResponse
    {
        $response = $this->httpClient->delete("admin/workers/{$workerId}", [], $this->getAdminHeaders());

        return SuccessResponse::fromArray($response);
    }

    /**
     * List all queues (admin).
     *
     * @param array<string, mixed> $params
     */
    public function listQueues(array $params = []): QueueList
    {
        $response = $this->httpClient->get('admin/queues', $params, $this->getAdminHeaders());

        return QueueList::fromArray($response);
    }

    /**
     * List all schedules (admin).
     *
     * @param array<string, mixed> $params
     */
    public function listSchedules(array $params = []): ScheduleList
    {
        $response = $this->httpClient->get('admin/schedules', $params, $this->getAdminHeaders());

        return ScheduleList::fromArray($response);
    }

    /**
     * List all workflows (admin).
     *
     * @param array<string, mixed> $params
     */
    public function listWorkflows(array $params = []): WorkflowList
    {
        $response = $this->httpClient->get('admin/workflows', $params, $this->getAdminHeaders());

        return WorkflowList::fromArray($response);
    }

    /**
     * Get system statistics (admin).
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->httpClient->get('admin/stats', [], $this->getAdminHeaders());
    }

    /**
     * Get system configuration (admin).
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->httpClient->get('admin/config', [], $this->getAdminHeaders());
    }

    /**
     * Update system configuration (admin).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function updateConfig(array $config): array
    {
        return $this->httpClient->put('admin/config', $config, [], $this->getAdminHeaders());
    }

    /**
     * Purge all jobs in a queue (admin).
     */
    public function purgeQueue(string $queueName): SuccessResponse
    {
        $response = $this->httpClient->post("admin/queues/{$queueName}/purge", null, [], $this->getAdminHeaders());

        return SuccessResponse::fromArray($response);
    }

    /**
     * Run maintenance tasks (admin).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function runMaintenance(array $params = []): array
    {
        return $this->httpClient->post('admin/maintenance', $params, [], $this->getAdminHeaders());
    }

    /**
     * Set organization limits (admin).
     *
     * @param array<string, int> $limits
     */
    public function setOrganizationLimits(string $orgId, array $limits): Organization
    {
        $response = $this->httpClient->put(
            "admin/organizations/{$orgId}/limits",
            $limits,
            [],
            $this->getAdminHeaders(),
        );

        return Organization::fromArray($response);
    }

    /**
     * Get admin headers with admin key.
     *
     * @return array<string, string>
     */
    private function getAdminHeaders(): array
    {
        $headers = [];

        if ($this->adminKey !== null && $this->adminKey !== '') {
            $headers['X-Admin-Key'] = $this->adminKey;
        }

        return $headers;
    }
}
