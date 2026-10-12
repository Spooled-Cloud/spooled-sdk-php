<?php

declare(strict_types=1);

namespace Spooled\Util;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Query helpers shared by the list endpoints.
 *
 * @internal
 */
final class ListQuery
{
    /** Query keys that take an RFC 3339 instant. */
    private const TIMESTAMP_KEYS = ['createdAfter', 'createdBefore', 'created_after', 'created_before', 'since'];

    private function __construct()
    {
    }

    /**
     * Normalize list filters for the query string:
     *
     * - `status` given as a list (strings or `JobStatus` cases) becomes the
     *   comma-separated form the API reads (`status=failed,deadletter`);
     *   PHP would otherwise send `status[0]=...&status[1]=...`.
     * - A `DateTimeInterface` under `createdAfter`, `createdBefore` or `since`
     *   becomes an RFC 3339 UTC timestamp. Strings are sent as given (the API
     *   answers 400 for one that is not RFC 3339).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function normalize(array $params): array
    {
        if (isset($params['status']) && is_array($params['status'])) {
            $params['status'] = implode(',', array_map(
                static fn (mixed $status): string => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
                $params['status'],
            ));
        } elseif (isset($params['status']) && $params['status'] instanceof BackedEnum) {
            $params['status'] = (string) $params['status']->value;
        }

        foreach (self::TIMESTAMP_KEYS as $key) {
            if (isset($params[$key]) && $params[$key] instanceof DateTimeInterface) {
                $params[$key] = self::rfc3339($params[$key]);
            }
        }

        return $params;
    }

    /**
     * An RFC 3339 UTC timestamp with microseconds, e.g. `2026-10-12T08:00:00.000000Z`.
     */
    public static function rfc3339(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * The value of an `X-Has-More` header: true, false, or null when the
     * header is absent or unreadable (callers then fall back to a guess).
     */
    public static function hasMore(?string $header): ?bool
    {
        if ($header === null) {
            return null;
        }

        return match (strtolower(trim($header))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
