<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Spooled\V1;

/**
 * Worker Service for worker management
 */
class WorkerServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Register a new worker
     * @param \Spooled\V1\RegisterWorkerRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\RegisterWorkerResponse>
     */
    public function Register(\Spooled\V1\RegisterWorkerRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.WorkerService/Register',
        $argument,
        ['\Spooled\V1\RegisterWorkerResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Send heartbeat
     * @param \Spooled\V1\HeartbeatRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\HeartbeatResponse>
     */
    public function Heartbeat(\Spooled\V1\HeartbeatRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.WorkerService/Heartbeat',
        $argument,
        ['\Spooled\V1\HeartbeatResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Deregister worker
     * @param \Spooled\V1\DeregisterRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Spooled\V1\DeregisterResponse>
     */
    public function Deregister(\Spooled\V1\DeregisterRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spooled.v1.WorkerService/Deregister',
        $argument,
        ['\Spooled\V1\DeregisterResponse', 'decode'],
        $metadata, $options);
    }

}
