<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\EmailCheckResponse;
use Spooled\Types\EmailLoginStartResponse;

#[CoversClass(EmailCheckResponse::class)]
#[CoversClass(EmailLoginStartResponse::class)]
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
}
