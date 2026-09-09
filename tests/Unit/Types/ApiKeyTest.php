<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\ApiKey;

#[CoversClass(ApiKey::class)]
final class ApiKeyTest extends TestCase
{
    #[Test]
    public function from_array_reads_is_active_and_last_used(): void
    {
        $got = ApiKey::fromArray([
            'id' => 'key_1',
            'name' => 'Revoked',
            'isActive' => false,
            'lastUsed' => '2024-01-02T00:00:00Z',
            'createdAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-12-01T00:00:00Z',
        ]);

        $this->assertFalse($got->active);
        $this->assertSame('2024-01-02T00:00:00Z', $got->lastUsedAt);
        $this->assertSame('2024-12-01T00:00:00Z', $got->expiresAt);
    }

    #[Test]
    public function from_array_keeps_active_true_when_is_active_is_true(): void
    {
        $got = ApiKey::fromArray([
            'id' => 'key_2',
            'name' => 'Live',
            'isActive' => true,
            'createdAt' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertTrue($got->active);
        $this->assertNull($got->lastUsedAt);
    }
}
