<?php

declare(strict_types=1);

namespace Spooled\Config;

/**
 * Retry policy configuration.
 */
final readonly class RetryConfig
{
    public const DEFAULT_MAX_RETRIES = 3;

    public const DEFAULT_BASE_DELAY = 1.0;

    public const DEFAULT_MAX_DELAY = 30.0;

    public const DEFAULT_FACTOR = 2.0;

    public const DEFAULT_JITTER = 0.1;

    public function __construct(
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public float $baseDelay = self::DEFAULT_BASE_DELAY,
        public float $maxDelay = self::DEFAULT_MAX_DELAY,
        public float $factor = self::DEFAULT_FACTOR,
        public float $jitter = self::DEFAULT_JITTER,
    ) {
    }

    /**
     * Calculate delay for a given attempt number.
     *
     * @param int $attempt Attempt number (0-indexed)
     * @param float|null $retryAfter Optional Retry-After header value
     */
    public function calculateDelay(int $attempt, ?float $retryAfter = null): float
    {
        // If Retry-After is provided, use it
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, $this->maxDelay);
        }

        // Exponential backoff: baseDelay * factor^attempt
        $delay = $this->baseDelay * ($this->factor ** $attempt);

        // Apply jitter (±jitter%)
        if ($this->jitter > 0) {
            $jitterRange = $delay * $this->jitter;
            $delay += (mt_rand() / mt_getrandmax() * 2 - 1) * $jitterRange;
        }

        // Clamp to max delay
        return min(max($delay, 0), $this->maxDelay);
    }

    /**
     * Check if the given attempt should be retried.
     */
    public function shouldRetry(int $attempt): bool
    {
        return $attempt < $this->maxRetries;
    }

    /**
     * Create a disabled retry config.
     */
    public static function disabled(): self
    {
        return new self(maxRetries: 0);
    }
}
