<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\WebhooksResource;
use Spooled\Types\RetryDeliveryResponse;
use Spooled\Types\TestWebhookResponse;

#[CoversClass(WebhooksResource::class)]
final class WebhooksResourceTest extends TestCase
{
    #[Test]
    public function test_maps_the_probe_body_not_a_delivery(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturn([
            'success' => true,
            'statusCode' => 200,
            'responseTimeMs' => 42,
        ]);

        $resource = new WebhooksResource($httpClient);
        $got = $resource->test('wh_1');

        $this->assertInstanceOf(TestWebhookResponse::class, $got);
        $this->assertTrue($got->success);
        $this->assertSame(200, $got->statusCode);
        $this->assertSame(42, $got->responseTimeMs);
    }

    #[Test]
    public function retry_delivery_maps_success_and_message_not_a_delivery(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient
            ->expects($this->once())
            ->method('post')
            ->with('outgoing-webhooks/wh_1/retry/del_1')
            ->willReturn([
                'success' => true,
                'message' => 'Delivery retried successfully',
            ]);

        $resource = new WebhooksResource($httpClient);
        $got = $resource->retryDelivery('wh_1', 'del_1');

        $this->assertInstanceOf(RetryDeliveryResponse::class, $got);
        $this->assertTrue($got->success);
        $this->assertSame('Delivery retried successfully', $got->message);
    }
}
