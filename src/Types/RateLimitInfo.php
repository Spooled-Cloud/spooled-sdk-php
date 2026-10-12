<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Rate-limit state the API reports in the `X-RateLimit-*` headers.
 *
 * The API sends `X-RateLimit-Limit`, `X-RateLimit-Remaining`,
 * `X-RateLimit-Reset` (Unix time in seconds) and `X-RateLimit-Scope` on
 * responses, and `Retry-After` on a 429. Read the latest values with
 * `SpooledClient::getLastRateLimit()`.
 */
final readonly class RateLimitInfo
{
    public function __construct(
        /** Requests allowed in the current window. */
        public ?int $limit,
        /** Requests left in the current window. */
        public ?int $remaining,
        /** Unix time (seconds) at which the window resets. */
        public ?int $reset,
        /** What the limit is counted against, for example `organization`. */
        public ?string $scope = null,
        /** Seconds to wait before retrying (`Retry-After`, sent on a 429). */
        public ?float $retryAfter = null,
    ) {
    }

    /**
     * Read the rate-limit headers of a response. Header names are matched
     * case-insensitively. Returns null when the response carries none of
     * `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`.
     *
     * @param array<string, string|array<string>> $headers
     */
    public static function fromHeaders(array $headers): ?self
    {
        $limit = self::intHeader($headers, 'X-RateLimit-Limit');
        $remaining = self::intHeader($headers, 'X-RateLimit-Remaining');
        $reset = self::intHeader($headers, 'X-RateLimit-Reset');

        if ($limit === null && $remaining === null && $reset === null) {
            return null;
        }

        $scope = self::header($headers, 'X-RateLimit-Scope');
        $retryAfter = self::header($headers, 'Retry-After');

        return new self(
            limit: $limit,
            remaining: $remaining,
            reset: $reset,
            scope: $scope !== null && $scope !== '' ? $scope : null,
            retryAfter: $retryAfter !== null && is_numeric($retryAfter) ? (float) $retryAfter : null,
        );
    }

    /**
     * Seconds until the window resets (never negative), or null when the
     * reset time is unknown.
     */
    public function secondsUntilReset(?int $now = null): ?int
    {
        if ($this->reset === null) {
            return null;
        }

        return max(0, $this->reset - ($now ?? time()));
    }

    /**
     * @param array<string, string|array<string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return $value === null ? null : trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param array<string, string|array<string>> $headers
     */
    private static function intHeader(array $headers, string $name): ?int
    {
        $value = self::header($headers, $name);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
