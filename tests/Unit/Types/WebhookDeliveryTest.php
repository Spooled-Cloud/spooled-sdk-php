<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\WebhookDelivery;

#[CoversClass(WebhookDelivery::class)]
final class WebhookDeliveryTest extends TestCase
{
    #[Test]
    public function from_array_reads_attempts_and_response_body(): void
    {
        $got = WebhookDelivery::fromArray([
            'id' => 'del_1',
            'webhookId' => 'wh_1',
            'event' => 'job.failed',
            'status' => 'failed',
            'statusCode' => 500,
            'attempts' => 3,
            'responseBody' => 'internal error',
            'error' => 'timeout',
            'createdAt' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertSame(3, $got->attemptNumber);
        $this->assertSame('internal error', $got->response);
        $this->assertSame('job.failed', $got->eventType);
        $this->assertSame(500, $got->statusCode);
    }

    #[Test]
    public function from_array_keeps_attempt_number_when_present(): void
    {
        $got = WebhookDelivery::fromArray([
            'id' => 'del_2',
            'webhookId' => 'wh_1',
            'eventType' => 'job.completed',
            'attemptNumber' => 2,
            'response' => 'ok',
        ]);

        $this->assertSame(2, $got->attemptNumber);
        $this->assertSame('ok', $got->response);
    }

    #[Test]
    public function from_array_keeps_non_object_json_payload(): void
    {
        $got = WebhookDelivery::fromArray([
            'id' => 'del_3',
            'webhookId' => 'wh_1',
            'event' => 'job.completed',
            'status' => 'success',
            'payload' => 'hello',
        ]);

        $this->assertSame('hello', $got->payload);
    }
}
