<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\HealthStatus;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    #[Test]
    public function from_array_maps_database_and_cache_onto_checks(): void
    {
        $got = HealthStatus::fromArray([
            'status' => 'healthy',
            'database' => true,
            'cache' => false,
        ]);

        $this->assertSame('healthy', $got->status);
        $this->assertTrue($got->isHealthy());
        $this->assertSame(['database' => true, 'cache' => false], $got->checks);
    }

    #[Test]
    public function from_array_treats_degraded_as_unhealthy(): void
    {
        $got = HealthStatus::fromArray([
            'status' => 'degraded',
            'database' => false,
            'cache' => true,
        ]);

        $this->assertFalse($got->isHealthy());
        $this->assertSame(['database' => false, 'cache' => true], $got->checks);
    }
}
