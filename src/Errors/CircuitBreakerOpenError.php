<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when circuit breaker is open.
 */
final class CircuitBreakerOpenError extends SpooledError
{
    public function __construct(
        string $message = 'Circuit breaker is open',
        public readonly string $state = 'open',
        public readonly int $failureCount = 0,
        public readonly ?float $openedAt = null,
        public readonly ?float $timeout = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 503, 'CIRCUIT_BREAKER_OPEN', [], null, null, $previous);
    }

    /**
     * Get the time until the circuit breaker will try half-open.
     */
    public function getTimeUntilRetry(): ?float
    {
        if ($this->openedAt === null || $this->timeout === null) {
            return null;
        }

        $elapsed = microtime(true) - $this->openedAt;

        return max(0, $this->timeout - $elapsed);
    }
}
