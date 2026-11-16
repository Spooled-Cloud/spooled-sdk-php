<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Webhooks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Webhooks\Signature;

#[CoversClass(Signature::class)]
final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const NOW = 1_800_000_000;

    private const BODY = '{"event":"job.completed","data":{"job_id":"j1"}}';

    #[Test]
    public function sign_matches_the_backend_scheme(): void
    {
        // HMAC-SHA256 keyed "whsec_test" over "1700000000.{}", computed with
        // printf '%s' '1700000000.{}' | openssl dgst -sha256 -hmac whsec_test
        $this->assertSame(
            'sha256=35495024f4ef3f94e5a93e22221544c4b75e9a42300cd965ab81cb85cd994e91',
            Signature::sign(self::SECRET, 1_700_000_000, '{}'),
        );
    }

    #[Test]
    public function it_accepts_a_valid_delivery(): void
    {
        $signature = Signature::sign(self::SECRET, self::NOW, self::BODY);

        $this->assertTrue(Signature::verify(self::SECRET, (string) self::NOW, $signature, self::BODY, 60, self::NOW));
        $this->assertTrue(Signature::verify(self::SECRET, ' ' . self::NOW . ' ', strtoupper($signature), self::BODY, 60, self::NOW));
        // Zero tolerance falls back to the 300 s default.
        $this->assertTrue(Signature::verify(self::SECRET, (string) self::NOW, $signature, self::BODY, 0, self::NOW + 240));
    }

    #[Test]
    public function it_accepts_a_fresh_delivery_against_the_clock(): void
    {
        $now = time();
        $signature = Signature::sign(self::SECRET, $now, self::BODY);

        $this->assertTrue(Signature::verify(self::SECRET, (string) $now, $signature, self::BODY));
    }

    #[Test]
    public function it_rejects_tampered_or_malformed_deliveries(): void
    {
        $ts = (string) self::NOW;
        $signature = Signature::sign(self::SECRET, self::NOW, self::BODY);

        $cases = [
            'tampered body' => [self::SECRET, $ts, $signature, '{"event":"job.failed"}', 60, self::NOW],
            'wrong secret' => ['whsec_other', $ts, $signature, self::BODY, 60, self::NOW],
            'other timestamp' => [self::SECRET, (string) (self::NOW + 1), $signature, self::BODY, 60, self::NOW],
            'too old' => [self::SECRET, $ts, $signature, self::BODY, 60, self::NOW + 120],
            'from the future' => [self::SECRET, $ts, $signature, self::BODY, 60, self::NOW - 120],
            'default tolerance exceeded' => [self::SECRET, $ts, $signature, self::BODY, 0, self::NOW + 400],
            'no prefix' => [self::SECRET, $ts, substr($signature, 7), self::BODY, 60, self::NOW],
            'missing signature' => [self::SECRET, $ts, null, self::BODY, 60, self::NOW],
            'empty signature' => [self::SECRET, $ts, '', self::BODY, 60, self::NOW],
            'missing timestamp' => [self::SECRET, null, $signature, self::BODY, 60, self::NOW],
            'non-numeric timestamp' => [self::SECRET, 'yesterday', $signature, self::BODY, 60, self::NOW],
            'empty secret' => ['', $ts, $signature, self::BODY, 60, self::NOW],
        ];

        foreach ($cases as $name => [$secret, $timestamp, $sig, $body, $tolerance, $now]) {
            $this->assertFalse(Signature::verify($secret, $timestamp, $sig, $body, $tolerance, $now), $name);
        }
    }
}
