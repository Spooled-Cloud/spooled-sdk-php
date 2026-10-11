<?php

declare(strict_types=1);

namespace Spooled\Util;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Converts the schedule times callers pass to Unix seconds.
 */
final class Timestamp
{
    private function __construct()
    {
    }

    /**
     * Accepts a DateTimeInterface, Unix seconds (int or numeric string) or an
     * ISO-8601 / strtotime-parsable string.
     *
     * A blind `(int)` cast turned "2026-10-10T12:00:00Z" into 2026, i.e. 1970,
     * so a scheduled job ran immediately.
     *
     * @throws InvalidArgumentException when the value cannot be read as a time
     */
    public static function toUnixSeconds(DateTimeInterface|int|float|string $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Unparseable scheduled time: {$value}", 0, $e);
        }
    }
}
