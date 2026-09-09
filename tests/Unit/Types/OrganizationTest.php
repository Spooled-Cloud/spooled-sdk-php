<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\Organization;

#[CoversClass(Organization::class)]
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
}
