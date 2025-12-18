<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\WebhookIngestionResource;

#[CoversClass(WebhookIngestionResource::class)]
final class WebhookIngestionResourceTest extends TestCase
{
    private WebhookIngestionResource $resource;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $this->resource = new WebhookIngestionResource($httpClient);
    }

    #[Test]
    public function it_generates_github_signature(): void
    {
        $payload = '{"action":"push","ref":"refs/heads/main"}';
        $secret = 'my-webhook-secret';

        $signature = $this->resource->generateGitHubSignature($payload, $secret);

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertSame(71, strlen($signature)); // sha256= + 64 hex chars
    }

    #[Test]
    public function it_validates_github_signature(): void
    {
        $payload = '{"action":"push","ref":"refs/heads/main"}';
        $secret = 'my-webhook-secret';

        $signature = $this->resource->generateGitHubSignature($payload, $secret);

        $this->assertTrue($this->resource->validateGitHubSignature($payload, $signature, $secret));
        $this->assertFalse($this->resource->validateGitHubSignature($payload, 'sha256=invalid', $secret));
        $this->assertFalse($this->resource->validateGitHubSignature('modified', $signature, $secret));
    }

    #[Test]
    public function it_generates_stripe_signature(): void
    {
        $payload = '{"type":"payment_intent.succeeded"}';
        $secret = 'whsec_test123';
        $timestamp = 1234567890;

        $signature = $this->resource->generateStripeSignature($payload, $secret, $timestamp);

        $this->assertStringStartsWith('t=1234567890,v1=', $signature);
        $this->assertStringContainsString('v1=', $signature);
    }

    #[Test]
    public function it_validates_stripe_signature(): void
    {
        $payload = '{"type":"payment_intent.succeeded"}';
        $secret = 'whsec_test123';
        $timestamp = time();

        $signature = $this->resource->generateStripeSignature($payload, $secret, $timestamp);

        $this->assertTrue($this->resource->validateStripeSignature($payload, $signature, $secret, 300));
    }

    #[Test]
    public function it_rejects_stripe_signature_with_wrong_secret(): void
    {
        $payload = '{"type":"payment_intent.succeeded"}';
        $timestamp = time();

        $signature = $this->resource->generateStripeSignature($payload, 'correct_secret', $timestamp);

        $this->assertFalse($this->resource->validateStripeSignature($payload, $signature, 'wrong_secret', 300));
    }

    #[Test]
    public function it_rejects_expired_stripe_signature(): void
    {
        $payload = '{"type":"payment_intent.succeeded"}';
        $secret = 'whsec_test123';
        $timestamp = time() - 400; // 400 seconds ago

        $signature = $this->resource->generateStripeSignature($payload, $secret, $timestamp);

        // With 300 second tolerance, this should fail
        $this->assertFalse($this->resource->validateStripeSignature($payload, $signature, $secret, 300));
    }

    #[Test]
    public function it_rejects_malformed_stripe_signature(): void
    {
        $payload = '{"type":"payment_intent.succeeded"}';
        $secret = 'whsec_test123';

        $this->assertFalse($this->resource->validateStripeSignature($payload, 'invalid', $secret));
        $this->assertFalse($this->resource->validateStripeSignature($payload, 't=123', $secret));
        $this->assertFalse($this->resource->validateStripeSignature($payload, 'v1=abc', $secret));
    }

    #[Test]
    public function github_signature_matches_expected_format(): void
    {
        // Test with known values
        $payload = 'test payload';
        $secret = 'test secret';

        // Expected: sha256=<hmac of "test payload" with key "test secret">
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $actual = $this->resource->generateGitHubSignature($payload, $secret);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function stripe_signature_uses_correct_format(): void
    {
        $payload = 'test payload';
        $secret = 'test secret';
        $timestamp = 1000000000;

        $signature = $this->resource->generateStripeSignature($payload, $secret, $timestamp);

        // Parse the signature
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        $this->assertArrayHasKey('t', $parts);
        $this->assertArrayHasKey('v1', $parts);
        $this->assertSame('1000000000', $parts['t']);

        // Verify the hash
        $signedPayload = "{$timestamp}.{$payload}";
        $expectedHash = hash_hmac('sha256', $signedPayload, $secret);
        $this->assertSame($expectedHash, $parts['v1']);
    }
}
