<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\CreateOrganizationResponse;
use Spooled\Types\Organization;
use Spooled\Types\WebhookToken;

#[CoversClass(Organization::class)]
#[CoversClass(CreateOrganizationResponse::class)]
#[CoversClass(WebhookToken::class)]
final class OrganizationTest extends TestCase
{
    #[Test]
    public function from_array_reads_plan_tier_not_plan(): void
    {
        $got = Organization::fromArray([
            'id' => 'org_1',
            'name' => 'Acme',
            'slug' => 'acme',
            'planTier' => 'pro',
            'billingEmail' => 'bills@acme.test',
            'createdAt' => '2024-01-01T00:00:00Z',
            'updatedAt' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertSame('pro', $got->plan);
        $this->assertSame('bills@acme.test', $got->billingEmail);
    }

    #[Test]
    public function create_response_keeps_the_one_time_api_key(): void
    {
        $got = CreateOrganizationResponse::fromArray([
            'organization' => [
                'id' => 'org_1',
                'name' => 'Acme',
                'slug' => 'acme',
                'planTier' => 'free',
                'createdAt' => '2024-01-01T00:00:00Z',
                'updatedAt' => '2024-01-01T00:00:00Z',
            ],
            'apiKey' => [
                'id' => 'key_1',
                'key' => 'sp_live_abc123',
                'name' => 'Default API Key',
                'createdAt' => '2024-01-01T00:00:00Z',
            ],
        ]);

        $this->assertSame('org_1', $got->organization->id);
        $this->assertSame('sp_live_abc123', $got->apiKey->key);
        $this->assertSame('key_1', $got->apiKey->id);
    }

    #[Test]
    public function webhook_token_reads_webhook_url(): void
    {
        $got = WebhookToken::fromArray([
            'webhookToken' => 'whk_abc123',
            'webhookUrl' => 'https://api.spooled.cloud/api/v1/webhooks/org_1/custom',
        ]);

        $this->assertSame('whk_abc123', $got->token);
        $this->assertSame(
            'https://api.spooled.cloud/api/v1/webhooks/org_1/custom',
            $got->url,
        );
    }
}
