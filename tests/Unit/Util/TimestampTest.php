<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Util;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Util\Timestamp;

#[CoversClass(Timestamp::class)]
final class TimestampTest extends TestCase
{
    #[Test]
    public function an_iso_8601_string_is_parsed_not_cast(): void
    {
        // (int) "2026-10-10T12:00:00Z" is 2026, i.e. 1970: the job ran immediately.
        $this->assertSame(1791633600, Timestamp::toUnixSeconds('2026-10-10T12:00:00Z'));
        $this->assertSame(1791633600, Timestamp::toUnixSeconds('2026-10-10T14:00:00+02:00'));
    }

    #[Test]
    public function numbers_and_date_objects_pass_through(): void
    {
        $this->assertSame(1791633600, Timestamp::toUnixSeconds(1791633600));
        $this->assertSame(1791633600, Timestamp::toUnixSeconds('1791633600'));
        $this->assertSame(1791633600, Timestamp::toUnixSeconds(1791633600.9));
        $this->assertSame(1791633600, Timestamp::toUnixSeconds(new DateTimeImmutable('@1791633600')));
    }

    #[Test]
    public function garbage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Timestamp::toUnixSeconds('not a time');
    }
}
