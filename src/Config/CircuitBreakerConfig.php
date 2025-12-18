<?php

declare(strict_types=1);

namespace Spooled\Config;

/**
 * Circuit breaker configuration.
 */
final readonly class CircuitBreakerConfig
{
    public const DEFAULT_FAILURE_THRESHOLD = 5;

    public const DEFAULT_SUCCESS_THRESHOLD = 2;

    public const DEFAULT_TIMEOUT = 30.0;

    public function __construct(
        public bool $enabled = true,
        public int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        public int $successThreshold = self::DEFAULT_SUCCESS_THRESHOLD,
        public float $timeout = self::DEFAULT_TIMEOUT,
    ) {
    }

    /**
     * Create a disabled circuit breaker config.
     */
    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
