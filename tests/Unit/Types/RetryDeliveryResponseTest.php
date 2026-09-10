<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\RetryDeliveryResponse;

#[CoversClass(RetryDeliveryResponse::class)]
final class RetryDeliveryResponseTest extends TestCase
{
    #[Test]
    public function from_array_reads_success_and_message(): void
    {
        $got = RetryDeliveryResponse::fromArray([
            'success' => true,
            'message' => 'Delivery retried successfully',
        ]);

        $this->assertTrue($got->success);
        $this->assertSame('Delivery retried successfully', $got->message);
    }

    #[Test]
    public function from_array_reads_a_failed_retry(): void
    {
        $got = RetryDeliveryResponse::fromArray([
            'success' => false,
            'message' => 'Delivery retry attempted but target did not return success',
        ]);

        $this->assertFalse($got->success);
        $this->assertSame(
            'Delivery retry attempted but target did not return success',
            $got->message,
        );
    }
}
