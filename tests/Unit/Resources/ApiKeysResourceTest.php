<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\ApiKeysResource;

#[CoversClass(ApiKeysResource::class)]
final class ApiKeysResourceTest extends TestCase
{
    #[Test]
    public function regenerate_creates_a_replacement_then_deletes_the_old_key(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('api-keys/key_old')
            ->willReturn([
                'id' => 'key_old',
                'name' => 'prod',
                'queues' => ['mail'],
                'rateLimit' => 50,
                'isActive' => true,
                'expiresAt' => '2027-01-01T00:00:00Z',
            ]);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('api-keys', [
                'name' => 'prod',
                'queues' => ['mail'],
                'rateLimit' => 50,
                'expiresAt' => '2027-01-01T00:00:00Z',
            ])
            ->willReturn([
                'id' => 'key_new',
                'key' => 'sp_new_secret',
                'name' => 'prod',
                'createdAt' => '2026-01-01T00:00:00Z',
            ]);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with('api-keys/key_old');

        $got = (new ApiKeysResource($httpClient))->regenerate('key_old');

        $this->assertSame('key_new', $got->id);
        $this->assertSame('sp_new_secret', $got->key);
        $this->assertSame('prod', $got->name);
    }
}
