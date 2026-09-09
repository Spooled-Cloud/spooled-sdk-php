<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\CreateOrganizationResponse;
use Spooled\Types\Organization;
use Spooled\Types\OrganizationUsage;
use Spooled\Types\WebhookToken;

#[CoversClass(Organization::class)]
#[CoversClass(CreateOrganizationResponse::class)]
#[CoversClass(WebhookToken::class)]
#[CoversClass(OrganizationUsage::class)]
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

    #[Test]
    public function usage_reads_jobs_today_and_warnings(): void
    {
        $got = OrganizationUsage::fromArray([
            'plan' => 'starter',
            'planDisplayName' => 'Starter',
            'limits' => [
                'tier' => 'starter',
                'displayName' => 'Starter',
                'maxJobsPerDay' => 10000,
                'maxActiveJobs' => 100,
            ],
            'usage' => [
                'jobsToday' => [
                    'current' => 500,
                    'limit' => 10000,
                    'percentage' => 5.0,
                    'isDisabled' => false,
                ],
                'activeJobs' => ['current' => 2, 'limit' => 100],
                'workflows' => ['current' => 1, 'limit' => 10],
            ],
            'warnings' => [
                ['resource' => 'jobs_today', 'message' => 'at 5%', 'severity' => 'warning'],
            ],
        ]);

        $this->assertSame('starter', $got->plan);
        $this->assertSame('Starter', $got->planDisplayName);
        $this->assertSame(10000, $got->limits->maxJobsPerDay);
        $this->assertSame(500, $got->usage->jobsToday?->current);
        $this->assertSame(5.0, $got->usage->jobsToday?->percentage);
        $this->assertSame(1, $got->usage->workflows?->current);
        $this->assertCount(1, $got->warnings);
        $this->assertSame('jobs_today', $got->warnings[0]->resource);
    }
}
