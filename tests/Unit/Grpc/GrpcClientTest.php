<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Grpc;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spooled\Grpc\GrpcOptions;
use Spooled\Grpc\GrpcQueueResource;
use Spooled\Grpc\GrpcWorkersResource;
use Spooled\Grpc\SpooledGrpcClient;
use Spooled\V1\CompleteRequest;
use Spooled\V1\CompleteResponse;
use Spooled\V1\DequeueRequest;
use Spooled\V1\DequeueResponse;
use Spooled\V1\FailRequest;
use Spooled\V1\FailResponse;
use Spooled\V1\Job;
use Spooled\V1\RegisterWorkerRequest;
use Spooled\V1\RegisterWorkerResponse;
use Spooled\V1\RenewLeaseRequest;
use Spooled\V1\RenewLeaseResponse;

final class ImmediateGrpcCall
{
    public function __construct(private readonly object $response)
    {
    }

    /** @return array{object, object} */
    public function wait(): array
    {
        return [$this->response, (object) ['code' => \Grpc\STATUS_OK, 'details' => '']];
    }
}

final class RecordingGrpcQueueClient
{
    /** @var array<string, object> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    public array $options = [];

    /** @param array<string, mixed> $options */
    public function Dequeue(DequeueRequest $request, array $metadata = [], array $options = []): ImmediateGrpcCall
    {
        $this->requests['dequeue'] = $request;
        $this->options['dequeue'] = $options;
        $job = new Job();
        $job->setId('job-1');
        $job->setQueueName('emails');
        $job->setLeaseId('lease-from-server');

        $response = new DequeueResponse();
        $response->setJobs([$job]);

        return new ImmediateGrpcCall($response);
    }

    /** @param array<string, mixed> $options */
    public function Complete(CompleteRequest $request, array $metadata = [], array $options = []): ImmediateGrpcCall
    {
        $this->requests['complete'] = $request;
        $this->options['complete'] = $options;
        $response = new CompleteResponse();
        $response->setSuccess(true);

        return new ImmediateGrpcCall($response);
    }

    /** @param array<string, mixed> $options */
    public function Fail(FailRequest $request, array $metadata = [], array $options = []): ImmediateGrpcCall
    {
        $this->requests['fail'] = $request;
        $this->options['fail'] = $options;
        $response = new FailResponse();
        $response->setSuccess(true);

        return new ImmediateGrpcCall($response);
    }

    /** @param array<string, mixed> $options */
    public function RenewLease(RenewLeaseRequest $request, array $metadata = [], array $options = []): ImmediateGrpcCall
    {
        $this->requests['renew'] = $request;
        $this->options['renew'] = $options;
        $response = new RenewLeaseResponse();
        $response->setSuccess(true);

        return new ImmediateGrpcCall($response);
    }
}

final class RecordingGrpcWorkerClient
{
    /** @var array<string, object> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    public array $options = [];

    /** @param array<string, mixed> $options */
    public function Register(RegisterWorkerRequest $request, array $metadata = [], array $options = []): ImmediateGrpcCall
    {
        $this->requests['register'] = $request;
        $this->options['register'] = $options;
        $response = new RegisterWorkerResponse();
        $response->setWorkerId('worker-123');
        $response->setLeaseDurationSecs(30);
        $response->setHeartbeatIntervalSecs(10);

        return new ImmediateGrpcCall($response);
    }
}

final class GrpcClientTest extends TestCase
{
    public function testGrpcOptionsFromArray(): void
    {
        $options = GrpcOptions::fromArray([
            'address' => 'localhost:50051',
            'apiKey' => 'test-key',
            'secure' => false,
            'timeout' => 30,
        ]);

        $this->assertSame('localhost:50051', $options->address);
        $this->assertSame('test-key', $options->apiKey);
        $this->assertFalse($options->secure);
        $this->assertSame(30.0, $options->timeout);
    }

    public function testGrpcOptionsDefaults(): void
    {
        $options = GrpcOptions::fromArray([
            'address' => 'grpc.spooled.cloud:443',
            'apiKey' => 'sp_test_test_key',
        ]);

        $this->assertSame('grpc.spooled.cloud:443', $options->address);
        $this->assertSame('sp_test_test_key', $options->apiKey);
        $this->assertTrue($options->secure);
        $this->assertNull($options->timeout);
    }

    public function testGrpcOptionsWithCustomTimeout(): void
    {
        $options = GrpcOptions::fromArray([
            'address' => 'localhost:50051',
            'apiKey' => 'test',
            'timeout' => 60,
        ]);

        $this->assertSame(60.0, $options->timeout);
    }

    public function testGrpcOptionsIsLocalhost(): void
    {
        $localhost = GrpcOptions::fromArray(['address' => 'localhost:50051']);
        $this->assertTrue($localhost->isLocalhost());

        $ip4 = GrpcOptions::fromArray(['address' => '127.0.0.1:50051']);
        $this->assertTrue($ip4->isLocalhost());

        $remote = GrpcOptions::fromArray(['address' => 'grpc.spooled.cloud:443']);
        $this->assertFalse($remote->isLocalhost());
    }

    #[RequiresPhpExtension('grpc')]
    public function testGrpcExtensionAvailable(): void
    {
        $this->assertTrue(extension_loaded('grpc'));
    }

    public function testGrpcOptionsTrimsTrailingNewlineFromApiKey(): void
    {
        $options = new GrpcOptions(
            address: 'grpc.spooled.cloud:443',
            apiKey: "sp_test_abc\n",
        );

        $this->assertSame('sp_test_abc', $options->apiKey);
    }

    public function testGrpcOptionsTrimsWhitespaceFromApiKey(): void
    {
        $options = new GrpcOptions(
            address: 'grpc.spooled.cloud:443',
            apiKey: "  \t sp_test_abc \r\n",
        );

        $this->assertSame('sp_test_abc', $options->apiKey);
    }

    public function testGrpcOptionsTreatsWhitespaceOnlyKeyAsNull(): void
    {
        $options = new GrpcOptions(address: 'localhost:50051', apiKey: "   \n");

        $this->assertNull($options->apiKey);
    }

    #[RequiresPhpExtension('grpc')]
    #[RequiresPhpExtension('protobuf')]
    public function testQueueOperationsSerializeLeaseAndDurationFields(): void
    {
        $transport = new RecordingGrpcQueueClient();
        $client = new SpooledGrpcClient(new GrpcOptions(address: 'localhost:50051', secure: false, timeout: 2.5));
        (new ReflectionClass(SpooledGrpcClient::class))->getProperty('queueClient')->setValue($client, $transport);
        $queue = new GrpcQueueResource($client);

        $dequeued = $queue->dequeue([
            'queueName' => 'emails',
            'workerId' => 'worker-1',
            'leaseDurationSecs' => 45,
            'batchSize' => 3,
        ]);
        $queue->complete([
            'jobId' => 'job-1',
            'workerId' => 'worker-1',
            'leaseId' => 'lease-current',
            'result' => ['ok' => true],
        ]);
        $queue->fail([
            'jobId' => 'job-1',
            'workerId' => 'worker-1',
            'leaseId' => 'lease-current',
            'error' => 'boom',
            'retry' => true,
        ]);
        $queue->renewLease([
            'jobId' => 'job-1',
            'workerId' => 'worker-1',
            'leaseId' => 'lease-current',
            'extensionSecs' => 60,
        ]);

        $this->assertSame('emails', $transport->requests['dequeue']->getQueueName());
        $this->assertSame('worker-1', $transport->requests['dequeue']->getWorkerId());
        $this->assertSame(45, $transport->requests['dequeue']->getLeaseDurationSecs());
        $this->assertSame(3, $transport->requests['dequeue']->getBatchSize());
        $this->assertSame('lease-from-server', $dequeued['jobs'][0]['leaseId']);

        $this->assertSame('job-1', $transport->requests['complete']->getJobId());
        $this->assertSame('worker-1', $transport->requests['complete']->getWorkerId());
        $this->assertSame('lease-current', $transport->requests['complete']->getLeaseId());

        $this->assertSame('job-1', $transport->requests['fail']->getJobId());
        $this->assertSame('worker-1', $transport->requests['fail']->getWorkerId());
        $this->assertSame('lease-current', $transport->requests['fail']->getLeaseId());
        $this->assertSame('boom', $transport->requests['fail']->getError());
        $this->assertTrue($transport->requests['fail']->getRetry());

        $this->assertSame('job-1', $transport->requests['renew']->getJobId());
        $this->assertSame('worker-1', $transport->requests['renew']->getWorkerId());
        $this->assertSame('lease-current', $transport->requests['renew']->getLeaseId());
        $this->assertSame(60, $transport->requests['renew']->getExtensionSecs());
        $this->assertSame(2_500_000, $transport->options['dequeue']['timeout']);
        $this->assertSame(2_500_000, $transport->options['complete']['timeout']);
        $this->assertSame(2_500_000, $transport->options['fail']['timeout']);
        $this->assertSame(2_500_000, $transport->options['renew']['timeout']);
    }

    #[RequiresPhpExtension('grpc')]
    #[RequiresPhpExtension('protobuf')]
    public function testWorkerRegisterSerializesTypeAndVersion(): void
    {
        $transport = new RecordingGrpcWorkerClient();
        $client = new SpooledGrpcClient(new GrpcOptions(address: 'localhost:50051', secure: false, timeout: 1.0));
        (new ReflectionClass(SpooledGrpcClient::class))->getProperty('workerClient')->setValue($client, $transport);
        $workers = new GrpcWorkersResource($client);

        $result = $workers->register([
            'queueName' => 'emails',
            'hostname' => 'php-worker-1',
            'workerType' => 'image-renderer',
            'version' => '9.9.9',
            'concurrency' => 4,
            'metadata' => ['zone' => 'eu'],
        ]);

        $request = $transport->requests['register'];
        $this->assertSame('worker-123', $result['workerId']);
        $this->assertSame('emails', $request->getQueueName());
        $this->assertSame('php-worker-1', $request->getHostname());
        $this->assertSame('image-renderer', $request->getWorkerType());
        $this->assertSame('9.9.9', $request->getVersion());
        $this->assertSame(4, $request->getMaxConcurrency());
        $this->assertSame(['zone' => 'eu'], iterator_to_array($request->getMetadata()));
        $this->assertSame(1_000_000, $transport->options['register']['timeout']);
    }
}
