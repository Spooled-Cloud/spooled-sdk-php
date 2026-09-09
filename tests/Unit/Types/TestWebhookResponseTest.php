<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\TestWebhookResponse;

#[CoversClass(TestWebhookResponse::class)]
final class TestWebhookResponseTest extends TestCase
{
    #[Test]
    public function from_array_reads_success_and_response_time(): void
    {
        $got = TestWebhookResponse::fromArray([
            'success' => true,
            'statusCode' => 200,
            'responseTimeMs' => 42,
        ]);

        $this->assertTrue($got->success);
        $this->assertSame(200, $got->statusCode);
        $this->assertSame(42, $got->responseTimeMs);
        $this->assertNull($got->error);
    }

    #[Test]
    public function from_array_reads_a_failed_probe(): void
    {
        $got = TestWebhookResponse::fromArray([
            'success' => false,
            'statusCode' => 500,
            'responseTimeMs' => 12,
            'error' => 'connection refused',
        ]);

        $this->assertFalse($got->success);
        $this->assertSame('connection refused', $got->error);
    }
}
