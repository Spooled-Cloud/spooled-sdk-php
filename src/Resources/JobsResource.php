<?php

declare(strict_types=1);

namespace Spooled\Resources;

use InvalidArgumentException;
use Spooled\Http\HttpClient;
use Spooled\Types\BatchStatusResponse;
use Spooled\Types\BulkEnqueueResponse;
use Spooled\Types\ClaimJobsResult;
use Spooled\Types\Job;
use Spooled\Types\JobList;
use Spooled\Types\JobStats;

/**
 * DLQ (Dead Letter Queue) operations interface.
 */
final class DlqOperations
{
    public function __construct(private readonly HttpClient $httpClient)
    {
    }

    /**
     * List jobs in dead-letter queue.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): JobList
    {
        $response = $this->httpClient->get('jobs/dlq', $params);

        return JobList::fromArray($response);
    }

    /**
     * Retry jobs from DLQ.
     *
     * @param array<string, mixed> $params
     * @return array{retriedCount: int, retriedJobs: string[]}
     */
    public function retry(array $params): array
    {
        return $this->httpClient->post('jobs/dlq/retry', $params);
    }

    /**
     * Purge jobs from DLQ.
     *
     * @param array<string, mixed> $params Must include 'confirm' => true
     * @return array{purgedCount: int}
     */
    public function purge(array $params): array
    {
        return $this->httpClient->post('jobs/dlq/purge', $params);
    }
}

/**
 * Jobs resource for managing jobs in the queue.
 */
final class JobsResource extends BaseResource
{
    /** Dead-letter queue operations */
    public readonly DlqOperations $dlq;

    public function __construct(HttpClient $httpClient)
    {
        parent::__construct($httpClient);
        $this->dlq = new DlqOperations($httpClient);
    }

    /**
     * Create a new job.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params): Job
    {
        // Map 'queue' to 'queueName' for API compatibility
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
            unset($params['queue']);
        }

        $response = $this->httpClient->post('jobs', $params);

        return Job::fromArray($response);
    }

    /**
     * Create a job and return the full job object.
     *
     * @param array<string, mixed> $params
     */
    public function createAndGet(array $params): Job
    {
        $result = $this->create($params);

        return $this->get($result->id);
    }

    /**
     * Get a job by ID.
     */
    public function get(string $jobId): Job
    {
        $response = $this->httpClient->get("jobs/{$jobId}");

        return Job::fromArray($response);
    }

    /**
     * List jobs.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): JobList
    {
        // Map 'queue' to 'queueName' for API compatibility
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
            unset($params['queue']);
        }

        $response = $this->httpClient->get('jobs', $params);

        return JobList::fromArray($response);
    }

    /**
     * Cancel a pending or scheduled job.
     */
    public function cancel(string $jobId): Job
    {
        $response = $this->httpClient->delete("jobs/{$jobId}");

        return Job::fromArray($response);
    }

    /**
     * Retry a failed or dead-lettered job.
     */
    public function retry(string $jobId): Job
    {
        $response = $this->httpClient->post("jobs/{$jobId}/retry");

        return Job::fromArray($response);
    }

    /**
     * Boost job priority.
     *
     * @return array{jobId: string, oldPriority: int, newPriority: int}
     */
    public function boostPriority(string $jobId, int $priority): array
    {
        return $this->httpClient->put("jobs/{$jobId}/priority", ['priority' => $priority]);
    }

    /**
     * Get job statistics.
     *
     * @param array<string, mixed> $params
     */
    public function getStats(array $params = []): JobStats
    {
        $response = $this->httpClient->get('jobs/stats', $params);

        return JobStats::fromArray($response);
    }

    /**
     * Get status of multiple jobs at once.
     *
     * @param array<string> $jobIds
     */
    public function batchStatus(array $jobIds): BatchStatusResponse
    {
        if (count($jobIds) === 0) {
            return BatchStatusResponse::fromArray(['statuses' => []]);
        }

        if (count($jobIds) > 100) {
            throw new InvalidArgumentException('Maximum 100 job IDs allowed per request');
        }

        $response = $this->httpClient->get('jobs/status', [
            'ids' => implode(',', $jobIds),
        ]);

        return BatchStatusResponse::fromArray($response);
    }

    /**
     * Bulk enqueue multiple jobs.
     *
     * @param array<string, mixed>|array<array<string, mixed>> $params Either BulkEnqueueParams or array of jobs
     */
    public function bulkEnqueue(array $params): BulkEnqueueResponse
    {
        // Support both formats: {queueName, jobs} or direct array of jobs
        if (isset($params['queueName']) || isset($params['jobs'])) {
            $response = $this->httpClient->post('jobs/bulk', $params);
        } else {
            // Direct array of jobs
            $response = $this->httpClient->post('jobs/bulk', ['jobs' => $params]);
        }

        return BulkEnqueueResponse::fromArray($response);
    }

    // Worker processing endpoints

    /**
     * Claim jobs for worker processing.
     *
     * @param array<string, mixed> $params {queueName, workerId, limit?, leaseDurationSecs?}
     */
    public function claim(array $params): ClaimJobsResult
    {
        // Map 'queue' to 'queueName' for API compatibility
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
            unset($params['queue']);
        }

        $response = $this->httpClient->post('jobs/claim', $params);

        return ClaimJobsResult::fromArray($response);
    }

    /**
     * Complete a job (worker ack).
     *
     * @param array<string, mixed> $params {workerId, result?}
     * @return array{success: bool}
     */
    public function complete(string $jobId, array $params): array
    {
        return $this->httpClient->post("jobs/{$jobId}/complete", $params);
    }

    /**
     * Fail a job (worker nack).
     *
     * @param array<string, mixed> $params {workerId, error}
     * @return array{success: bool, error?: string}
     */
    public function fail(string $jobId, array $params): array
    {
        return $this->httpClient->post("jobs/{$jobId}/fail", $params);
    }

    /**
     * Extend job lease (heartbeat).
     *
     * @param array<string, mixed> $params {workerId, leaseDurationSecs}
     * @return array{success: bool}
     */
    public function heartbeat(string $jobId, array $params): array
    {
        return $this->httpClient->post("jobs/{$jobId}/heartbeat", $params);
    }

    // Legacy DLQ methods for backward compatibility

    /**
     * @deprecated Use $this->dlq->list() instead
     * @param array<string, mixed> $params
     */
    public function dlqList(array $params = []): JobList
    {
        return $this->dlq->list($params);
    }

    /**
     * @deprecated Use $this->dlq->retry() instead
     * @param array<string, mixed> $params
     * @return array{retriedCount: int, retriedJobs: string[]}
     */
    public function dlqRetry(array $params): array
    {
        return $this->dlq->retry($params);
    }

    /**
     * @deprecated Use $this->dlq->purge() instead
     * @param array<string, mixed> $params
     * @return array{purgedCount: int}
     */
    public function dlqPurge(array $params): array
    {
        return $this->dlq->purge($params);
    }
}
