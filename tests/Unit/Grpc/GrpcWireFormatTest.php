<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Grpc;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spooled\Grpc\GrpcOptions;
use Spooled\Grpc\GrpcQueueResource;
use Spooled\Grpc\GrpcWorkersResource;
use Spooled\Grpc\SpooledGrpcClient;
use Spooled\V1\FailRequest;
use Spooled\V1\FailResponse;
use Spooled\V1\RegisterWorkerRequest;
use Spooled\V1\RegisterWorkerResponse;

// The status constants come from ext-grpc. These tests only need the
// standard numeric codes, so they also run on the pure-PHP protobuf runtime.
if (!defined('Grpc\STATUS_OK')) {
    define('Grpc\STATUS_OK', 0);
}
if (!defined('Grpc\STATUS_NOT_FOUND')) {
    define('Grpc\STATUS_NOT_FOUND', 5);
}

final class WireCall
{
    public function __construct(private readonly object $response)
    {
    }

    /** @return array{object, object} */
    public function wait(): array
    {
        return [$this->response, (object) ['code' => 0, 'details' => '']];
    }
}

/**
 * Stands in for the generated service clients and keeps the exact bytes each
 * request would put on the wire.
 */
final class WireRecordingTransport
{
    /** @var array<string, string> */
    public array $bytes = [];

    /** @param array<string, mixed> $options */
    public function Fail(FailRequest $request, array $metadata = [], array $options = []): WireCall
    {
        $this->bytes['fail'] = $request->serializeToString();
        $response = new FailResponse();
        $response->setSuccess(true);

        return new WireCall($response);
    }

    /** @param array<string, mixed> $options */
    public function Register(RegisterWorkerRequest $request, array $metadata = [], array $options = []): WireCall
    {
        $this->bytes['register'] = $request->serializeToString();
        $response = new RegisterWorkerResponse();
        $response->setWorkerId($request->getWorkerId() !== '' ? $request->getWorkerId() : 'server-minted');

        return new WireCall($response);
    }
}

/**
 * The serialized messages, not just the getters: `retry` is `optional bool`
 * since backend 0.1.119, and only a field that is present on the wire tells
 * the server "false". Before the stubs were regenerated, `retry => false`
 * produced no field 4 at all, so the job was retried instead of dead-lettered.
 */
final class GrpcWireFormatTest extends TestCase
{
    private WireRecordingTransport $transport;

    private GrpcQueueResource $queue;

    private GrpcWorkersResource $workers;

    protected function setUp(): void
    {
        $this->transport = new WireRecordingTransport();
        $client = new SpooledGrpcClient(new GrpcOptions(address: 'localhost:50051', secure: false));
        $reflection = new ReflectionClass(SpooledGrpcClient::class);
        $reflection->getProperty('queueClient')->setValue($client, $this->transport);
        $reflection->getProperty('workerClient')->setValue($client, $this->transport);
        $this->queue = new GrpcQueueResource($client);
        $this->workers = new GrpcWorkersResource($client);
    }

    public function testFailWithRetryFalsePutsFieldFourFalseOnTheWire(): void
    {
        $this->queue->fail([
            'jobId' => 'job-1',
            'workerId' => 'w',
            'leaseId' => 'l',
            'error' => 'boom',
            'retry' => false,
        ]);

        // 0a job_id, 12 worker_id, 1a error, 20 00 = field 4 (varint) false, 2a lease_id
        $this->assertSame(
            '0a056a6f622d31' . '120177' . '1a04626f6f6d' . '2000' . '2a016c',
            bin2hex($this->transport->bytes['fail']),
        );

        $decoded = new FailRequest();
        $decoded->mergeFromString($this->transport->bytes['fail']);
        $this->assertTrue($decoded->hasRetry(), 'retry=false must be present, not omitted');
        $this->assertFalse($decoded->getRetry());
    }

    public function testFailRetriesByDefaultWithAnExplicitTrue(): void
    {
        $this->queue->fail(['jobId' => 'job-1', 'workerId' => 'w', 'leaseId' => 'l', 'error' => 'boom']);
        $this->assertStringContainsString("\x20\x01", $this->transport->bytes['fail']);

        $this->queue->fail(['jobId' => 'job-1', 'workerId' => 'w', 'leaseId' => 'l', 'error' => 'boom', 'retry' => true]);
        $decoded = new FailRequest();
        $decoded->mergeFromString($this->transport->bytes['fail']);
        $this->assertTrue($decoded->hasRetry());
        $this->assertTrue($decoded->getRetry());
    }

    public function testRegisterSendsTheStableWorkerIdAsFieldSeven(): void
    {
        $result = $this->workers->register([
            'queueName' => 'emails',
            'hostname' => 'h',
            'workerId' => 'php-worker-1',
        ]);

        $this->assertSame('php-worker-1', $result['workerId']);
        $decoded = new RegisterWorkerRequest();
        $decoded->mergeFromString($this->transport->bytes['register']);
        $this->assertTrue($decoded->hasWorkerId());
        $this->assertSame('php-worker-1', $decoded->getWorkerId());
        // field 7, length-delimited: tag 0x3a
        $this->assertStringContainsString("\x3a\x0c" . 'php-worker-1', $this->transport->bytes['register']);
    }

    public function testRegisterWithoutWorkerIdLeavesTheFieldOut(): void
    {
        $result = $this->workers->register(['queueName' => 'emails', 'hostname' => 'h', 'maxConcurrency' => 3]);

        $this->assertSame('server-minted', $result['workerId']);
        $decoded = new RegisterWorkerRequest();
        $decoded->mergeFromString($this->transport->bytes['register']);
        $this->assertFalse($decoded->hasWorkerId());
        $this->assertSame(3, $decoded->getMaxConcurrency());
    }
}
