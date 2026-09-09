<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\AuthResource;

#[CoversClass(AuthResource::class)]
final class AuthResourceTest extends TestCase
{
    #[Test]
    public function logout_sends_refresh_token_so_the_session_cannot_refresh(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('getRefreshToken')
            ->willReturn('rt_1');
        $httpClient->expects($this->once())
            ->method('post')
            ->with('auth/logout', ['refreshToken' => 'rt_1'])
            ->willReturn([]);

        $got = (new AuthResource($httpClient))->logout();

        $this->assertTrue($got->success);
    }

    #[Test]
    public function logout_prefers_an_explicit_refresh_token(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->never())->method('getRefreshToken');
        $httpClient->expects($this->once())
            ->method('post')
            ->with('auth/logout', ['refreshToken' => 'rt_explicit'])
            ->willReturn([]);

        $got = (new AuthResource($httpClient))->logout('rt_explicit');

        $this->assertTrue($got->success);
    }
}
