<?php

declare(strict_types=1);

namespace Spooled\Util;

use Spooled\Config\CircuitBreakerConfig;
use Spooled\Errors\CircuitBreakerOpenError;
use Throwable;

/**
 * Circuit breaker implementation.
 *
 * Implements the circuit breaker pattern to prevent cascading failures
 * when a service is experiencing issues.
 */
final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';

    public const STATE_OPEN = 'open';

    public const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;

    private int $failureCount = 0;

    private int $successCount = 0;

    private ?float $openedAt = null;

    public function __construct(
        private readonly CircuitBreakerConfig $config,
    ) {
    }

    /**
     * Get the current state of the circuit breaker.
     */
    public function getState(): string
    {
        $this->checkStateTransition();

        return $this->state;
    }

    /**
     * Check if the circuit breaker allows requests.
     */
    public function isAllowed(): bool
    {
        if (!$this->config->enabled) {
            return true;
        }

        $this->checkStateTransition();

        return $this->state !== self::STATE_OPEN;
    }

    /**
     * Record a successful request.
     */
    public function recordSuccess(): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $this->checkStateTransition();

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->successCount++;

            if ($this->successCount >= $this->config->successThreshold) {
                $this->transitionTo(self::STATE_CLOSED);
            }
        } elseif ($this->state === self::STATE_CLOSED) {
            // Reset failure count on success in closed state
            $this->failureCount = 0;
        }
    }

    /**
     * Record a failed request.
     */
    public function recordFailure(): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $this->checkStateTransition();

        if ($this->state === self::STATE_HALF_OPEN) {
            // Any failure in half-open goes back to open
            $this->transitionTo(self::STATE_OPEN);
        } elseif ($this->state === self::STATE_CLOSED) {
            $this->failureCount++;

            if ($this->failureCount >= $this->config->failureThreshold) {
                $this->transitionTo(self::STATE_OPEN);
            }
        }
    }

    /**
     * Execute a callable with circuit breaker protection.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws CircuitBreakerOpenError
     */
    public function execute(callable $fn): mixed
    {
        if (!$this->isAllowed()) {
            throw new CircuitBreakerOpenError(
                'Circuit breaker is open',
                state: $this->state,
                failureCount: $this->failureCount,
                openedAt: $this->openedAt,
                timeout: $this->config->timeout,
            );
        }

        try {
            $result = $fn();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            // Only record failure for transport/server errors, not client errors
            if ($this->isRetryableError($e)) {
                $this->recordFailure();
            }

            throw $e;
        }
    }

    /**
     * Reset the circuit breaker to closed state.
     */
    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->openedAt = null;
    }

    /**
     * Get failure count.
     */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Get success count (in half-open state).
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * Get time when circuit opened.
     */
    public function getOpenedAt(): ?float
    {
        return $this->openedAt;
    }

    /**
     * Check and perform state transition based on timeout.
     */
    private function checkStateTransition(): void
    {
        if ($this->state === self::STATE_OPEN && $this->openedAt !== null) {
            $elapsed = microtime(true) - $this->openedAt;

            if ($elapsed >= $this->config->timeout) {
                $this->transitionTo(self::STATE_HALF_OPEN);
            }
        }
    }

    /**
     * Transition to a new state.
     */
    private function transitionTo(string $newState): void
    {
        $this->state = $newState;

        switch ($newState) {
            case self::STATE_OPEN:
                $this->openedAt = microtime(true);
                $this->successCount = 0;
                break;

            case self::STATE_HALF_OPEN:
                $this->successCount = 0;
                break;

            case self::STATE_CLOSED:
                $this->failureCount = 0;
                $this->successCount = 0;
                $this->openedAt = null;
                break;
        }
    }

    /**
     * Check if an error should trigger the circuit breaker.
     */
    private function isRetryableError(Throwable $e): bool
    {
        // Don't trigger circuit breaker for client errors (4xx except 429)
        if (method_exists($e, 'getStatusCode')) {
            $status = $e->getStatusCode();

            // 429 (rate limit) should trigger circuit breaker
            if ($status === 429) {
                return true;
            }

            // Other 4xx errors are client errors, don't trigger
            if ($status >= 400 && $status < 500) {
                return false;
            }
        }

        return true;
    }
}
