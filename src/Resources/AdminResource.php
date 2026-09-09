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
        $response = $this->httpClient->patch("admin/organizations/{$orgId}", $params, [], $this->getAdminHeaders());

        return Organization::fromArray($response);
    }

    /**
     * Delete an organization (admin).
     *
     * Soft-delete by default. `$hardDelete` sends `?hard_delete=true`, which
     * permanently removes the org and its data. `?hard=true` is ignored.
     */
    public function deleteOrganization(string $orgId, bool $hardDelete = false): SuccessResponse
    {
        $query = $hardDelete ? ['hard_delete' => 'true'] : [];
        $response = $this->httpClient->delete(
            "admin/organizations/{$orgId}",
            $query,
            $this->getAdminHeaders(),
        );

        return SuccessResponse::fromArray($response);
    }

    /**
     * List jobs.
     *
     * There is no `GET /admin/jobs`. The backend contract is `GET /jobs`.
     *
     * @param array<string, mixed> $params
     */
    public function listJobs(array $params = []): JobList
    {
        $response = $this->httpClient->get('jobs', $params, $this->getAdminHeaders());

        return JobList::fromArray($response);
    }

    /**
     * Get a job by ID.
     *
     * There is no `GET /admin/jobs/{id}`. The backend contract is `GET /jobs/{id}`.
     */
    public function getJob(string $jobId): Job
    {
        $response = $this->httpClient->get("jobs/{$jobId}", [], $this->getAdminHeaders());

        return Job::fromArray($response);
    }

    /**
     * Cancel a pending or scheduled job.
     *
     * There is no `POST /admin/jobs/{id}/cancel`. The backend contract is
     * `DELETE /jobs/{id}`, which returns 204. Parsing that empty body as a Job
     * produced id="" and status="pending", so we fetch the cancelled row.
     */
    public function cancelJob(string $jobId): Job
    {
        $this->httpClient->delete("jobs/{$jobId}", [], $this->getAdminHeaders());

        $response = $this->httpClient->get("jobs/{$jobId}", [], $this->getAdminHeaders());

        return Job::fromArray($response);
    }

    /**
     * List workers.
     *
     * There is no `GET /admin/workers`. The backend contract is `GET /workers`.
     *
     * @param array<string, mixed> $params
     */
    public function listWorkers(array $params = []): WorkerList
    {
        $response = $this->httpClient->get('workers', $params, $this->getAdminHeaders());

        return WorkerList::fromArray($response);
    }

    /**
     * Get a worker by ID.
     *
     * There is no `GET /admin/workers/{id}`. The backend contract is `GET /workers/{id}`.
     */
    public function getWorker(string $workerId): Worker
    {
        $response = $this->httpClient->get("workers/{$workerId}", [], $this->getAdminHeaders());

        return Worker::fromArray($response);
    }

    /**
     * Deregister a worker.
     *
     * There is no `DELETE /admin/workers/{id}`. The backend contract is
     * `POST /workers/{id}/deregister` (DELETE on that path 405s).
     */
    public function deregisterWorker(string $workerId): SuccessResponse
    {
        $response = $this->httpClient->post(
            "workers/{$workerId}/deregister",
            null,
            [],
            $this->getAdminHeaders(),
        );

        return SuccessResponse::fromArray($response);
    }

    /**
     * List queues.
     *
     * There is no `GET /admin/queues`. The backend contract is `GET /queues`.
     *
     * @param array<string, mixed> $params
     */
    public function listQueues(array $params = []): QueueList
    {
        $response = $this->httpClient->get('queues', $params, $this->getAdminHeaders());

        return QueueList::fromArray($response);
    }

    /**
     * List schedules.
     *
     * There is no `GET /admin/schedules`. The backend contract is `GET /schedules`.
     *
     * @param array<string, mixed> $params
     */
    public function listSchedules(array $params = []): ScheduleList
    {
        $response = $this->httpClient->get('schedules', $params, $this->getAdminHeaders());

        return ScheduleList::fromArray($response);
    }

    /**
     * List workflows.
     *
     * There is no `GET /admin/workflows`. The backend contract is `GET /workflows`.
     *
     * @param array<string, mixed> $params
     */
    public function listWorkflows(array $params = []): WorkflowList
    {
        $response = $this->httpClient->get('workflows', $params, $this->getAdminHeaders());

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
     * Delete a queue and all of its jobs.
     *
     * There is no `POST /admin/queues/{name}/purge`. The backend contract is
     * `DELETE /queues/{name}?delete_jobs=true`.
     */
    public function purgeQueue(string $queueName): SuccessResponse
    {
        $response = $this->httpClient->delete(
            "queues/{$queueName}",
            ['delete_jobs' => 'true'],
            $this->getAdminHeaders(),
        );

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
     * There is no `PUT /admin/organizations/{id}/limits`. Custom limits are
     * `PATCH /admin/organizations/{id}` with `custom_limits`.
     *
     * @param array<string, mixed> $limits
     */
    public function setOrganizationLimits(string $orgId, array $limits): Organization
    {
        $response = $this->httpClient->patch(
            "admin/organizations/{$orgId}",
            ['customLimits' => $limits],
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
