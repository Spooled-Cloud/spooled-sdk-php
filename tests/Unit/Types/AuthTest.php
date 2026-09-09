<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\CurrentUserResponse;
use Spooled\Types\EmailCheckResponse;
use Spooled\Types\EmailLoginStartResponse;
use Spooled\Types\TokenValidation;

#[CoversClass(CurrentUserResponse::class)]
#[CoversClass(EmailCheckResponse::class)]
#[CoversClass(EmailLoginStartResponse::class)]
#[CoversClass(TokenValidation::class)]
final class AuthTest extends TestCase
{
    #[Test]
    public function check_email_maps_backend_shape(): void
    {
        $got = EmailCheckResponse::fromArray([
            'available' => true,
            'exists' => false,
            'signupEnabled' => false,
        ]);

        $this->assertFalse($got->exists);
        $this->assertTrue($got->available);
        $this->assertFalse($got->signupEnabled);
        $this->assertFalse($got->canRegister);
    }

    #[Test]
    public function check_email_taken_is_not_can_register(): void
    {
        $got = EmailCheckResponse::fromArray([
            'available' => false,
            'exists' => true,
            'signupEnabled' => true,
        ]);

        $this->assertTrue($got->exists);
        $this->assertFalse($got->available);
        $this->assertTrue($got->signupEnabled);
        $this->assertFalse($got->canRegister);
    }

    #[Test]
    public function email_start_maps_email_sent_to(): void
    {
        $got = EmailLoginStartResponse::fromArray([
            'message' => 'Login code sent to your email',
            'emailSentTo' => 'n***@example.com',
        ]);

        $this->assertSame('Login code sent to your email', $got->message);
        $this->assertSame('n***@example.com', $got->emailSentTo);
        $this->assertTrue($got->success);
        $this->assertNull($got->codeExpiresIn);
    }

    #[Test]
    public function me_maps_session_fields_not_an_email_user(): void
    {
        $got = CurrentUserResponse::fromArray([
            'organizationId' => 'org_1',
            'apiKeyId' => 'key_1',
            'queues' => ['emails'],
            'issuedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-01-01T01:00:00Z',
            'organization' => [
                'id' => 'org_1',
                'name' => 'Acme',
                'slug' => 'acme',
                'planTier' => 'pro',
            ],
        ]);

        $this->assertSame('org_1', $got->organizationId);
        $this->assertSame('key_1', $got->apiKeyId);
        $this->assertSame(['emails'], $got->queues);
        $this->assertSame('2024-01-01T00:00:00Z', $got->issuedAt);
        $this->assertSame('pro', $got->organization?->plan);
    }

    #[Test]
    public function validate_maps_claims_not_a_nested_user(): void
    {
        $got = TokenValidation::fromArray([
            'valid' => true,
            'claims' => [
                'orgId' => 'org_1',
                'apiKeyId' => 'key_1',
                'queues' => ['emails'],
                'exp' => 1700003600,
                'iat' => 1700000000,
            ],
        ]);

        $this->assertTrue($got->valid);
        $this->assertNull($got->user);
        $this->assertSame('org_1', $got->organizationId);
        $this->assertSame('key_1', $got->apiKeyId);
        $this->assertSame(['emails'], $got->scopes);
        $this->assertSame(1700003600, $got->expiresAt);
        $this->assertNull($got->error);
    }

    #[Test]
    public function validate_maps_error_when_invalid(): void
    {
        $got = TokenValidation::fromArray([
            'valid' => false,
            'error' => 'Invalid token',
        ]);

        $this->assertFalse($got->valid);
        $this->assertSame('Invalid token', $got->error);
        $this->assertNull($got->organizationId);
    }
}
