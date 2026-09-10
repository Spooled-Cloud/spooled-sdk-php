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

    #[Test]
    public function email_verify_maps_signup_token_instead_of_empty_auth_tokens(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('auth/email/verify', ['email' => 'new@user.com', 'code' => '123456'])
            ->willReturn([
                'type' => 'signup',
                'signupToken' => 'signup-token-123',
                'email' => 'new@user.com',
                'expiresIn' => 900,
            ]);

        $got = (new AuthResource($httpClient))->emailVerify('new@user.com', '123456');

        $this->assertSame('signup', $got->type);
        $this->assertSame('signup-token-123', $got->signupToken);
        $this->assertNull($got->accessToken);
    }

    #[Test]
    public function register_posts_signup_complete_not_missing_register_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('auth/signup/complete', [
                'signupToken' => 'tok',
                'name' => 'Acme',
                'slug' => 'acme',
            ])
            ->willReturn([
                'organization' => [
                    'id' => 'org_1',
                    'name' => 'Acme',
                    'slug' => 'acme',
                    'billingEmail' => 'new@user.com',
                ],
                'apiKey' => 'sp_live_once',
                'accessToken' => 'at_1',
                'refreshToken' => 'rt_1',
                'tokenType' => 'Bearer',
                'expiresIn' => 86400,
            ]);

        $got = (new AuthResource($httpClient))->register([
            'signupToken' => 'tok',
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $this->assertSame('sp_live_once', $got->apiKey);
        $this->assertSame('at_1', $got->accessToken);
        $this->assertSame('org_1', $got->organizationId);
    }
}
