<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Spooled\V1;

/**
 * Spooled Queue Service
 * High-performance gRPC API for job queue operations
 */
class QueueServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Enqueue a new job
     * @param \Spooled\V1\EnqueueRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\EnqueueResponse>
     */
    public function Enqueue(\Spooled\V1\EnqueueRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/Enqueue',
        $argument,
        ['\Spooled\V1\EnqueueResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Dequeue a job (for workers)
     * @param \Spooled\V1\DequeueRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\DequeueResponse>
     */
    public function Dequeue(\Spooled\V1\DequeueRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/Dequeue',
        $argument,
        ['\Spooled\V1\DequeueResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Complete a job
     * @param \Spooled\V1\CompleteRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\CompleteResponse>
     */
    public function Complete(\Spooled\V1\CompleteRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/Complete',
        $argument,
        ['\Spooled\V1\CompleteResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Fail a job
     * @param \Spooled\V1\FailRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\FailResponse>
     */
    public function Fail(\Spooled\V1\FailRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/Fail',
        $argument,
        ['\Spooled\V1\FailResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Renew job lease
     * @param \Spooled\V1\RenewLeaseRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\RenewLeaseResponse>
     */
    public function RenewLease(\Spooled\V1\RenewLeaseRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/RenewLease',
        $argument,
        ['\Spooled\V1\RenewLeaseResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Get job status
     * @param \Spooled\V1\GetJobRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\GetJobResponse>
     */
    public function GetJob(\Spooled\V1\GetJobRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/GetJob',
        $argument,
        ['\Spooled\V1\GetJobResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Get queue statistics
     * @param \Spooled\V1\GetQueueStatsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\GetQueueStatsResponse>
     */
    public function GetQueueStats(\Spooled\V1\GetQueueStatsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.QueueService/GetQueueStats',
        $argument,
        ['\Spooled\V1\GetQueueStatsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Stream jobs to workers (server-side streaming)
     * Note: Streaming is implemented via REST API WebSocket/SSE endpoints:
     *   - GET /api/v1/ws (WebSocket for bidirectional real-time updates)
     *   - GET /api/v1/events (SSE for job event streaming)
     *   - GET /api/v1/events/jobs/{id} (SSE for single job updates)
     *   - GET /api/v1/events/queues/{name} (SSE for queue updates)
     * @param \Spooled\V1\StreamJobsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function StreamJobs(\Spooled\V1\StreamJobsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/spooled.v1.QueueService/StreamJobs',
        $argument,
        ['\Spooled\V1\Job', 'decode'],
        $metadata, $options);
    }

    /**
     * Bidirectional streaming for real-time job processing
     * Note: Use WebSocket endpoint /api/v1/ws for bidirectional communication
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\BidiStreamingCall
     */
    public function ProcessJobs($metadata = [], $options = []) {
        return $this->_bidiRequest('/spooled.v1.QueueService/ProcessJobs',
        ['\Spooled\V1\ProcessResponse','decode'],
        $metadata, $options);
    }

}
