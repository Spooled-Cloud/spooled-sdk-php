<?php

declare(strict_types=1);

namespace Spooled\Webhooks;

/**
 * Verifies the signature Spooled puts on outgoing webhooks and on job
 * completion callbacks created with a secret.
 *
 * A signed delivery carries `X-Spooled-Timestamp` (Unix seconds) and
 * `X-Spooled-Signature: sha256=<hex>`, the hex HMAC-SHA256 of
 * `"{timestamp}.{body}"` keyed with the webhook secret.
 *
 * ```php
 * $body = file_get_contents('php://input');
 * $ok = Signature::verify(
 *     getenv('SPOOLED_WEBHOOK_SECRET'),
 *     $_SERVER['HTTP_X_SPOOLED_TIMESTAMP'] ?? null,
 *     $_SERVER['HTTP_X_SPOOLED_SIGNATURE'] ?? null,
 *     $body,
 * );
 * if (!$ok) {
 *     http_response_code(401);
 *     exit;
 * }
 * ```
 */
final class Signature
{
    public const SIGNATURE_HEADER = 'X-Spooled-Signature';

    public const TIMESTAMP_HEADER = 'X-Spooled-Timestamp';

    /** Accepted distance between the timestamp and now, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * The `X-Spooled-Signature` value Spooled sends for `$body` signed at
     * `$timestamp`. Useful in tests of a webhook handler.
     */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Check a delivery. Pass the raw request body exactly as received (before
     * any JSON decoding) and the two header values.
     *
     * Returns true only when the secret is non-empty, the signature has the
     * `sha256=<hex>` form and matches (compared in constant time), and the
     * timestamp is within `$toleranceSeconds` of now (in either direction). A
     * tolerance of zero or less uses DEFAULT_TOLERANCE.
     *
     * @param int|null $now Unix time to check against; defaults to time()
     */
    public static function verify(
        string $secret,
        ?string $timestampHeader,
        ?string $signatureHeader,
        string $body,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $timestampHeader === null || $signatureHeader === null) {
            return false;
        }

        $timestamp = trim($timestampHeader);
        if (preg_match('/^\d{1,19}$/', $timestamp) !== 1) {
            return false;
        }

        $signature = strtolower(trim($signatureHeader));
        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $tolerance = $toleranceSeconds > 0 ? $toleranceSeconds : self::DEFAULT_TOLERANCE;
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return hash_equals($expected, $signature);
    }
}
