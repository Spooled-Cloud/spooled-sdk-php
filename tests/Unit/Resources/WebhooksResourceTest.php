<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\WebhooksResource;
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
}
