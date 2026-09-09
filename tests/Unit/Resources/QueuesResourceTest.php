<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\QueuesResource;

#[CoversClass(QueuesResource::class)]
final class QueuesResourceTest extends TestCase
{
    #[Test]
    public function delete_without_flag_does_not_send_delete_jobs(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with('queues/emails', [])
            ->willReturn([]);

        $got = (new QueuesResource($httpClient))->delete('emails');

        $this->assertTrue($got->success);
    }

    #[Test]
    public function purge_uses_delete_jobs_query_not_a_missing_post_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with('queues/emails', ['delete_jobs' => 'true'])
            ->willReturn([]);
        $httpClient->expects($this->never())->method('post');

        $got = (new QueuesResource($httpClient))->purge('emails');

        $this->assertTrue($got->success);
    }
}
