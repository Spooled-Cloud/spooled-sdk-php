<?php

declare(strict_types=1);

namespace Spooled\Grpc;

use DateTime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Spooled\Util\Timestamp;
use Throwable;

/**
 * gRPC client for high-performance operations.
 *
 * Note: Requires ext-grpc, ext-protobuf, and google/protobuf packages.
 */
final class SpooledGrpcClient
{
    private readonly GrpcOptions $options;

    private readonly LoggerInterface $logger;

    private mixed $queueClient = null;

    private mixed $workerClient = null;

    /** @var GrpcQueueResource|null */
    public ?GrpcQueueResource $queue = null;

    /** @var GrpcWorkersResource|null */
    public ?GrpcWorkersResource $workers = null;

    public function __construct(
        GrpcOptions $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->options = $options;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Check if gRPC support is available.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('grpc') && extension_loaded('protobuf');
    }

    /**
     * Wait for the gRPC channel to be ready.
     *
     * @param DateTime|null $deadline Maximum time to wait
     * @throws RuntimeException if connection fails
     */
    public function waitForReady(?DateTime $deadline = null): void
    {
        $this->ensureAvailable();

        // Try to get/create the client - this validates the connection
        try {
            $this->getQueueClient();
            $this->getWorkerClient();

            // Initialize sub-resources
            $this->queue = new GrpcQueueResource($this);
            $this->workers = new GrpcWorkersResource($this);

            $this->logger->debug('gRPC client ready', ['address' => $this->options->address]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Failed to connect to gRPC server at ' . $this->options->address . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Ensure gRPC is available.
     */
    private function ensureAvailable(): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                'gRPC support requires ext-grpc and ext-protobuf extensions. ' .
                'Install with: pecl install grpc protobuf',
            );
        }
    }

    /**
     * Get or create the gRPC queue service client.
     */
    public function getQueueClient(): mixed
    {
        // A client that already exists (created below, or injected by a test
        // transport) is reused as is; only creating one needs the extensions.
        if ($this->queueClient !== null) {
            return $this->queueClient;
        }

        $this->ensureAvailable();

        if ($this->queueClient === null) {
            $credentials = $this->options->secure
                ? \Grpc\ChannelCredentials::createSsl()
                : \Grpc\ChannelCredentials::createInsecure();

            $clientClass = '\\Spooled\\V1\\QueueServiceClient';

            if (!class_exists($clientClass)) {
                throw new RuntimeException(
                    'gRPC stubs not generated. Run: php scripts/generate-grpc.php',
                );
            }

            $this->queueClient = new $clientClass(
                $this->options->address,
                [
                    'credentials' => $credentials,
                ],
            );
        }

        return $this->queueClient;
    }

    /**
     * Get or create the gRPC worker service client.
     */
    public function getWorkerClient(): mixed
    {
        if ($this->workerClient !== null) {
            return $this->workerClient;
        }

        $this->ensureAvailable();

        if ($this->workerClient === null) {
            $credentials = $this->options->secure
                ? \Grpc\ChannelCredentials::createSsl()
                : \Grpc\ChannelCredentials::createInsecure();

            $clientClass = '\\Spooled\\V1\\WorkerServiceClient';

            if (!class_exists($clientClass)) {
                throw new RuntimeException(
                    'gRPC stubs not generated. Run: php scripts/generate-grpc.php',
                );
            }

            $this->workerClient = new $clientClass(
                $this->options->address,
                [
                    'credentials' => $credentials,
                ],
            );
        }

        return $this->workerClient;
    }

    /**
     * Get metadata with authentication.
     *
     * @return array<string, array<string>>
     */
    public function getMetadata(): array
    {
        $metadata = [];

        if ($this->options->apiKey !== null) {
            $metadata['x-api-key'] = [$this->options->apiKey];
        }

        return $metadata;
    }

    /**
     * Get unary call options.
     *
     * @return array<string, int>
     */
    public function getCallOptions(): array
    {
        if ($this->options->timeout === null) {
            return [];
        }

        return ['timeout' => max(1, (int) round($this->options->timeout * 1_000_000))];
    }

    /**
     * Close the gRPC connection.
     */
    public function close(): void
    {
        if ($this->queueClient !== null) {
            $this->queueClient->close();
            $this->queueClient = null;
        }
        if ($this->workerClient !== null) {
            $this->workerClient->close();
            $this->workerClient = null;
        }
        $this->queue = null;
        $this->workers = null;
    }
}

/**
 * gRPC Queue operations resource.
 */
final class GrpcQueueResource
{
    public function __construct(
        private readonly SpooledGrpcClient $client,
    ) {
    }

    /**
     * Enqueue a job via gRPC.
     *
     * The payload travels as a `google.protobuf.Struct`, so it must be a JSON
     * object (a list or scalar payload does not arrive as sent) and integers
     * beyond 2^53 lose precision. Use REST (`$client->jobs->create()`) for such
     * payloads; REST keeps them exactly.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function enqueue(array $params): array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\EnqueueRequest();
        $request->setQueueName((string) ($params['queueName'] ?? $params['queue'] ?? 'default'));

        // Set payload as JSON string
        $payload = $params['payload'] ?? [];
        if (is_array($payload)) {
            $request->setPayload(new \Google\Protobuf\Struct());
            // For now, convert to JSON and back
            $struct = new \Google\Protobuf\Struct();
            $struct->mergeFromJsonString(json_encode($payload));
            $request->setPayload($struct);
        }

        if (isset($params['priority'])) {
            $request->setPriority((int) $params['priority']);
        }

        if (isset($params['maxRetries'])) {
            $request->setMaxRetries((int) $params['maxRetries']);
        }

        if (isset($params['timeoutSeconds'])) {
            $request->setTimeoutSeconds((int) $params['timeoutSeconds']);
        } elseif (isset($params['timeout_seconds'])) {
            $request->setTimeoutSeconds((int) $params['timeout_seconds']);
        }

        if (isset($params['idempotencyKey'])) {
            $request->setIdempotencyKey((string) $params['idempotencyKey']);
        }

        if (isset($params['scheduledAt'])) {
            $timestamp = new \Google\Protobuf\Timestamp();
            $timestamp->setSeconds(Timestamp::toUnixSeconds($params['scheduledAt']));
            $request->setScheduledAt($timestamp);
        }

        [$response, $status] = $client->Enqueue($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'jobId' => $response->getJobId(),
            'id' => $response->getJobId(),
            'created' => $response->getCreated(),
        ];
    }

    /**
     * Dequeue a job via gRPC.
     *
     * Params: `queueName` (or `queue`), `workerId`, `batchSize` and
     * `leaseDurationSecs` (omitted: the server's 300 s lease). Each returned job
     * carries the `leaseId` that complete/fail/renewLease must send back.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function dequeue(array $params): ?array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\DequeueRequest();
        $request->setQueueName((string) ($params['queueName'] ?? $params['queue'] ?? 'default'));

        if (isset($params['workerId'])) {
            $request->setWorkerId((string) $params['workerId']);
        }

        if (isset($params['leaseDurationSecs'])) {
            $request->setLeaseDurationSecs((int) $params['leaseDurationSecs']);
        }

        if (isset($params['batchSize'])) {
            $request->setBatchSize((int) $params['batchSize']);
        }

        [$response, $status] = $client->Dequeue($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            if ($status->code === \Grpc\STATUS_NOT_FOUND) {
                return null;
            }

            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        $jobs = $response->getJobs();
        $result = ['jobs' => []];

        if ($jobs !== null) {
            foreach ($jobs as $job) {
                $payload = [];
                if ($job->getPayload() !== null) {
                    $payload = json_decode($job->getPayload()->serializeToJsonString(), true) ?? [];
                }

                $result['jobs'][] = [
                    'id' => $job->getId(),
                    'jobId' => $job->getId(),
                    'queueName' => $job->getQueueName(),
                    'payload' => $payload,
                    'status' => $job->getStatus(),
                    'retryCount' => $job->getRetryCount(),
                    'maxRetries' => $job->getMaxRetries(),
                    // Lease fencing token; echo back on complete/fail/renewLease
                    'leaseId' => $job->getLeaseId() !== '' ? $job->getLeaseId() : null,
                ];
            }
        }

        return $result;
    }

    /**
     * Get job by ID via gRPC.
     *
     * @return array<string, mixed>
     */
    public function getJob(string $jobId): array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\GetJobRequest();
        $request->setJobId($jobId);

        [$response, $status] = $client->GetJob($request, $metadata, $this->client->getCallOptions())->wait();

        // Return null job for NOT_FOUND (matches Node.js SDK behavior)
        if ($status->code === \Grpc\STATUS_NOT_FOUND) {
            return ['job' => null];
        }

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        $job = $response->getJob();
        if ($job === null) {
            return ['job' => null];
        }

        $payload = [];
        if ($job->getPayload() !== null) {
            $payload = json_decode($job->getPayload()->serializeToJsonString(), true) ?? [];
        }

        return [
            'job' => [
                'id' => $job->getId(),
                'jobId' => $job->getId(),
                'queueName' => $job->getQueueName(),
                'payload' => $payload,
                'status' => $job->getStatus(),
                'retryCount' => $job->getRetryCount(),
                'maxRetries' => $job->getMaxRetries(),
                // Lease fencing token; echo back on complete/fail/renewLease
                'leaseId' => $job->getLeaseId() !== '' ? $job->getLeaseId() : null,
            ],
        ];
    }

    /**
     * Complete a job via gRPC.
     *
     * Params: `jobId`, `workerId`, `leaseId` (required by the server: the
     * `leaseId` the dequeue returned; without it the call is INVALID_ARGUMENT)
     * and `result`. `result` travels as a `google.protobuf.Struct`: a JSON
     * object only, and integers beyond 2^53 lose precision (REST keeps them).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function complete(array $params): array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\CompleteRequest();
        $request->setJobId((string) $params['jobId']);

        if (isset($params['workerId'])) {
            $request->setWorkerId((string) $params['workerId']);
        }

        if (isset($params['result'])) {
            $struct = new \Google\Protobuf\Struct();
            $struct->mergeFromJsonString(json_encode($params['result']));
            $request->setResult($struct);
        }

        // Lease fencing token from dequeue; when set, completion succeeds only
        // if it matches the job's current lease.
        if (isset($params['leaseId'])) {
            $request->setLeaseId((string) $params['leaseId']);
        }

        [$response, $status] = $client->Complete($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'success' => $response->getSuccess(),
        ];
    }

    /**
     * Fail a job via gRPC.
     *
     * Params: `jobId`, `workerId`, `leaseId` (required by the server: the
     * `leaseId` the dequeue returned; without it the call is INVALID_ARGUMENT),
     * `error`, and `retry`. `retry` omitted or true retries the job according to
     * its `max_retries`; `retry => false` moves it to the dead-letter queue now.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function fail(array $params): array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\FailRequest();
        $request->setJobId((string) $params['jobId']);
        $request->setError((string) ($params['error'] ?? 'Unknown error'));

        if (isset($params['workerId'])) {
            $request->setWorkerId((string) $params['workerId']);
        }

        // `retry` is `optional bool` (field 4): true or omitted retries per the
        // job's max_retries, false dead-letters the job now. Always sent, so a
        // server from before the field had presence (where omitted meant false)
        // still retries by default. Only an explicit false dead-letters.
        $request->setRetry(!isset($params['retry']) || (bool) $params['retry']);

        // Lease fencing token from dequeue; when set, the failure succeeds only
        // if it matches the job's current lease.
        if (isset($params['leaseId'])) {
            $request->setLeaseId((string) $params['leaseId']);
        }

        [$response, $status] = $client->Fail($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'willRetry' => $response->getWillRetry(),
            'nextRetryDelaySecs' => $response->getNextRetryDelaySecs(),
        ];
    }

    /**
     * Get queue statistics via gRPC.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getStats(array $params): array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\GetQueueStatsRequest();
        $request->setQueueName((string) ($params['queueName'] ?? $params['queue'] ?? 'default'));

        [$response, $status] = $client->GetQueueStats($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'queueName' => $response->getQueueName(),
            'pending' => $response->getPending(),
            'processing' => $response->getProcessing(),
            'completed' => $response->getCompleted(),
            'failed' => $response->getFailed(),
            'deadLettered' => $response->getDeadletter(),
        ];
    }

    /**
     * Renew job lease via gRPC.
     *
     * Params: `jobId`, `workerId`, `leaseId` (the `leaseId` the dequeue
     * returned) and `extensionSecs`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function renewLease(array $params): array
    {
        $client = $this->client->getQueueClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\RenewLeaseRequest();
        $request->setJobId((string) $params['jobId']);
        $request->setWorkerId((string) $params['workerId']);

        if (isset($params['extensionSecs'])) {
            $request->setExtensionSecs((int) $params['extensionSecs']);
        }

        // Lease fencing token from dequeue; when set, the renewal succeeds only
        // if it matches the job's current lease.
        if (isset($params['leaseId'])) {
            $request->setLeaseId((string) $params['leaseId']);
        }

        [$response, $status] = $client->RenewLease($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'success' => $response->getSuccess(),
            'newExpiresAt' => $response->getNewExpiresAt()?->getSeconds(),
        ];
    }
}

/**
 * gRPC Workers operations resource.
 */
final class GrpcWorkersResource
{
    public function __construct(
        private readonly SpooledGrpcClient $client,
    ) {
    }

    /**
     * Register a worker via gRPC.
     *
     * Params: `queueName` (or `queue`), `hostname`, `workerType`, `version`,
     * `concurrency` (or `maxConcurrency`), `metadata` (string map) and the
     * optional stable `workerId` (1-128 characters of letters, digits, `-`,
     * `_`, `.`). Registering again with the same `workerId` updates that worker
     * instead of adding one; an id another organization uses is ALREADY_EXISTS.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function register(array $params): array
    {
        $client = $this->client->getWorkerClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\RegisterWorkerRequest();
        $request->setQueueName((string) ($params['queueName'] ?? $params['queue'] ?? 'default'));
        $request->setHostname((string) ($params['hostname'] ?? gethostname()));
        $request->setWorkerType((string) ($params['workerType'] ?? $params['worker_type'] ?? 'php'));
        $request->setVersion((string) ($params['version'] ?? \Spooled\Version::VERSION));

        // Stable worker id (optional, 1-128 chars of letters, digits, `-`, `_`,
        // `.`): registering again with the same id updates that worker instead
        // of adding one, so a restart does not count twice against the plan's
        // worker limit. Omitted or empty: the server assigns a new id.
        $workerId = $params['workerId'] ?? $params['worker_id'] ?? null;
        if ($workerId !== null && (string) $workerId !== '') {
            $request->setWorkerId((string) $workerId);
        }

        // `maxConcurrency` (the REST name) is accepted next to `concurrency`.
        $concurrency = $params['concurrency'] ?? $params['maxConcurrency'] ?? null;
        if ($concurrency !== null) {
            $request->setMaxConcurrency((int) $concurrency);
        }

        if (isset($params['metadata']) && is_array($params['metadata'])) {
            // metadata is a map<string, string> in protobuf
            $metadataArray = [];
            foreach ($params['metadata'] as $key => $value) {
                $metadataArray[(string) $key] = (string) $value;
            }
            $request->setMetadata($metadataArray);
        }

        [$response, $status] = $client->Register($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'workerId' => $response->getWorkerId(),
            'leaseDurationSecs' => $response->getLeaseDurationSecs(),
            'heartbeatIntervalSecs' => $response->getHeartbeatIntervalSecs(),
        ];
    }

    /**
     * Send worker heartbeat via gRPC.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function heartbeat(array $params): array
    {
        $client = $this->client->getWorkerClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\HeartbeatRequest();
        $request->setWorkerId((string) $params['workerId']);

        if (isset($params['currentJobs'])) {
            $request->setCurrentJobs((int) $params['currentJobs']);
        }

        if (isset($params['status'])) {
            $request->setStatus((string) $params['status']);
        }

        [$response, $status] = $client->Heartbeat($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'acknowledged' => $response->getAcknowledged(),
            'shouldDrain' => $response->getShouldDrain(),
        ];
    }

    /**
     * Deregister a worker via gRPC.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function deregister(array $params): array
    {
        $client = $this->client->getWorkerClient();
        $metadata = $this->client->getMetadata();

        $request = new \Spooled\V1\DeregisterRequest();
        $request->setWorkerId((string) $params['workerId']);

        [$response, $status] = $client->Deregister($request, $metadata, $this->client->getCallOptions())->wait();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw new RuntimeException("gRPC error: {$status->details}", $status->code);
        }

        return [
            'success' => $response->getSuccess(),
        ];
    }
}
