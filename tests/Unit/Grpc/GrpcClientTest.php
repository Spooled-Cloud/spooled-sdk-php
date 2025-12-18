<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Grpc;

use PHPUnit\Framework\TestCase;
use Spooled\Grpc\GrpcOptions;

/**
 * @group grpc
 */
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
            'apiKey' => 'sk_live_test',
        ]);

        $this->assertSame('grpc.spooled.cloud:443', $options->address);
        $this->assertSame('sk_live_test', $options->apiKey);
        $this->assertTrue($options->secure); // Default
        $this->assertNull($options->timeout); // Default is null
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

    /**
     * @requires extension grpc
     */
    public function testGrpcExtensionAvailable(): void
    {
        $this->assertTrue(extension_loaded('grpc'));
    }
}
