<?php

declare(strict_types=1);

namespace Spooled\Resources;

use DateTimeInterface;
use InvalidArgumentException;
use Spooled\Http\HttpClient;
use Spooled\Types\BatchStatusResponse;
use Spooled\Types\BulkEnqueueResponse;
use Spooled\Types\ClaimJobsResult;
use Spooled\Types\CreateJobResult;
use Spooled\Types\Job;
use Spooled\Types\JobHistoryList;
use Spooled\Types\JobList;
use Spooled\Types\JobStats;
use Spooled\Types\JobStatus;
use Spooled\Util\FailureText;
use Spooled\Util\ListQuery;

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
     * Takes the same filters as {@see JobsResource::list()} except `status`.
     * `hasMore` comes from the API's `X-Has-More` header.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): JobList
    {
        $params = ListQuery::normalize(self::mapQueueAlias($params));
        $response = $this->httpClient->get('jobs/dlq', $params);

        return JobList::fromArray(
            $response,
            self::requestedLimit($params),
            ListQuery::hasMore($this->httpClient->getLastResponseHeader('X-Has-More')),
        );
    }

    /**
     * Retry jobs from DLQ.
     *
     * @param array<string, mixed> $params
     * @return array{retriedCount: int, retriedJobs: string[]}
     */
    public function retry(array $params): array
    {
        return $this->httpClient->post('jobs/dlq/retry', self::mapQueueAlias($params));
    }

    /**
     * Purge jobs from DLQ.
     *
     * @param array<string, mixed> $params Must include 'confirm' => true
     * @return array{purgedCount: int}
     */
    public function purge(array $params): array
    {
        return $this->httpClient->post('jobs/dlq/purge', self::mapQueueAlias($params));
    }

    /**
     * Map the documented `queue` alias to the API's `queueName`.
     *
     * The DLQ retry and purge bodies accept only `queue_name` and ignore
     * unknown keys, so an unmapped `queue` silently widened the operation to
     * EVERY queue of the organization (a purge deleted dead-lettered jobs
     * everywhere). `create`/`list`/`claim` already map it; these did not.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function mapQueueAlias(array $params): array
    {
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
        }
        unset($params['queue']);

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function requestedLimit(array $params): ?int
    {
        return isset($params['limit']) ? (int) $params['limit'] : null;
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
    public function create(array $params): CreateJobResult
    {
        // Map 'queue' to 'queueName' for API compatibility
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
            unset($params['queue']);
        }
        // Map SDK alias scheduledFor → API scheduledAt
        if (isset($params['scheduledFor']) && !isset($params['scheduledAt'])) {
            $params['scheduledAt'] = $params['scheduledFor'];
            unset($params['scheduledFor']);
        }

        // POST is not retried by default (a lost response would duplicate the job).
        // With an idempotency key the server deduplicates, so a retry is safe.
        $idempotent = ($params['idempotencyKey'] ?? $params['idempotency_key'] ?? '') !== '';
        $response = $this->httpClient->post('jobs', $params, forceRetry: $idempotent);

        return CreateJobResult::fromArray($response);
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
     * Filters (all optional, combined with AND):
     *
     * - `queue` / `queueName`: one queue
     * - `status`: one status (`'failed'`), a comma list (`'failed,deadletter'`),
     *   or a list of strings / `JobStatus` cases (`['failed', 'deadletter']`)
     * - `q`: search text, case-insensitive; matches a job whose id starts with it
     *   or whose queue name or `payload.job_type` contains it
     * - `jobType`: exact `payload.job_type`
     * - `createdAfter` / `createdBefore`: RFC 3339 string or `DateTimeInterface`
     *   (created at or after / before that instant); anything else is a 400
     * - `tag`: one tag
     * - `limit` (1-100, default 50), `offset`, `orderBy`, `orderDir`
     *
     * `hasMore` on the result comes from the API's `X-Has-More` header.
     *
     * @param array{
     *     queue?: string,
     *     queueName?: string,
     *     status?: string|JobStatus|array<string|JobStatus>,
     *     q?: string,
     *     jobType?: string,
     *     createdAfter?: string|DateTimeInterface,
     *     createdBefore?: string|DateTimeInterface,
     *     tag?: string,
     *     limit?: int,
     *     offset?: int,
     *     orderBy?: string,
     *     orderDir?: string
     * }|array<string, mixed> $params
     */
    public function list(array $params = []): JobList
    {
        // Map 'queue' to 'queueName' for API compatibility
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
            unset($params['queue']);
        }

        $params = ListQuery::normalize($params);
        $response = $this->httpClient->get('jobs', $params);

        // GET /jobs is a bare array; the X-Has-More header says whether another
        // page follows. The requested limit is the fallback for older servers.
        return JobList::fromArray(
            $response,
            isset($params['limit']) ? (int) $params['limit'] : null,
            ListQuery::hasMore($this->httpClient->getLastResponseHeader('X-Has-More')),
        );
    }

    /**
     * A job's recorded lifecycle events (`GET /jobs/{id}/history`).
     *
     * Params: `order` (`'asc'`, the default: oldest first; or `'desc'`),
     * `limit` (1-500, default 100) and `offset`. `hasMore` comes from the
     * API's `X-Has-More` header. A job of another organization, or one in a
     * queue outside the API key's scope, is a NotFoundError.
     *
     * @param array{order?: 'asc'|'desc', orderDir?: 'asc'|'desc', limit?: int, offset?: int}|array<string, mixed> $params
     */
    public function history(string $jobId, array $params = []): JobHistoryList
    {
        $response = $this->httpClient->get("jobs/{$jobId}/history", $params);

        return JobHistoryList::fromArray(
            $response,
            isset($params['limit']) ? (int) $params['limit'] : null,
            ListQuery::hasMore($this->httpClient->getLastResponseHeader('X-Has-More')),
        );
    }

    /**
     * Cancel a pending or scheduled job.
     *
     * DELETE /jobs/{id} returns 204 with an empty body. Parsing that as a Job
     * produced id="" and status="pending". The cancelled row still exists, so
     * we fetch it.
     */
    public function cancel(string $jobId): Job
    {
        $this->httpClient->delete("jobs/{$jobId}");

        return $this->get($jobId);
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
        if (isset($params['queueName']) || isset($params['queue']) || isset($params['jobs'])) {
            // Map 'queue' to 'queueName' (the API requires queue_name; the
            // documented `['queue' => ...]` form always failed validation).
            if (isset($params['queue']) && !isset($params['queueName'])) {
                $params['queueName'] = $params['queue'];
            }
            unset($params['queue']);
            $response = $this->httpClient->post('jobs/bulk', $params);
        } else {
            // Direct array of jobs: the API takes one queue for the whole batch,
            // so it can only be inferred when every job names the same queue.
            $queues = array_unique(array_map(
                static fn (mixed $job): string => is_array($job) ? (string) ($job['queueName'] ?? $job['queue'] ?? '') : '',
                $params,
            ));
            if (count($queues) !== 1 || $queues[array_key_first($queues)] === '') {
                throw new InvalidArgumentException(
                    'bulkEnqueue() needs a queue: pass [\'queueName\' => ..., \'jobs\' => [...]] '
                    . 'or a list of jobs that all name the same queueName.',
                );
            }
            $response = $this->httpClient->post('jobs/bulk', [
                'queueName' => (string) reset($queues),
                'jobs' => array_map(static function (mixed $job): mixed {
                    if (is_array($job)) {
                        unset($job['queueName'], $job['queue']);
                    }

                    return $job;
                }, $params),
            ]);
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
     * Retried on network errors, timeouts and 5xx (bounded by the client's
     * retry config): the call is fenced by the lease, and a completion lost to
     * a dropped connection would otherwise re-run the job after its lease expires.
     * If the first attempt actually landed, the retry is answered with
     * 409 "already completed" (a ConflictError).
     *
     * `leaseId` is required: pass the `leaseId` of the claimed job. The API
     * answers 422 (ValidationError) without it.
     *
     * @param array<string, mixed> $params {workerId, leaseId, result?}
     * @return array{success: bool}
     */
    public function complete(string $jobId, array $params): array
    {
        return $this->httpClient->post("jobs/{$jobId}/complete", $params, forceRetry: true);
    }

    /**
     * Fail a job (worker nack). Retried like {@see self::complete()}.
     *
     * The error text is coerced to what the API accepts (never empty, at most
     * 2048 characters); an empty or over-long message is rejected with a 400,
     * which left the job `processing` until its lease expired.
     *
     * `leaseId` is required: pass the `leaseId` of the claimed job. The API
     * answers 422 (ValidationError) without it.
     *
     * @param array<string, mixed> $params {workerId, leaseId, error}
     * @return array{success: bool, error?: string}
     */
    public function fail(string $jobId, array $params): array
    {
        $params['error'] = FailureText::coerce(isset($params['error']) ? (string) $params['error'] : null);

        return $this->httpClient->post("jobs/{$jobId}/fail", $params, forceRetry: true);
    }

    /**
     * Extend job lease (heartbeat). Idempotent, so it is retried like {@see self::complete()}.
     *
     * `leaseId` is required: pass the `leaseId` of the claimed job. The API
     * answers 422 (ValidationError) without it.
     *
     * @param array<string, mixed> $params {workerId, leaseId, leaseDurationSecs?}
     * @return array{success: bool}
     */
    public function heartbeat(string $jobId, array $params): array
    {
        return $this->httpClient->post("jobs/{$jobId}/heartbeat", $params, forceRetry: true);
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
