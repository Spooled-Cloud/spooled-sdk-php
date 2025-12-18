<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spooled\Config\CircuitBreakerConfig;
use Spooled\Errors\CircuitBreakerOpenError;
use Spooled\Util\CircuitBreaker;

#[CoversClass(CircuitBreaker::class)]
final class CircuitBreakerTest extends TestCase
{
    #[Test]
    public function it_starts_in_closed_state(): void
    {
        $cb = new CircuitBreaker(new CircuitBreakerConfig());

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAllowed());
    }

    #[Test]
    public function it_allows_requests_when_disabled(): void
    {
        $cb = new CircuitBreaker(CircuitBreakerConfig::disabled());

        // Even after failures, should allow requests when disabled
        for ($i = 0; $i < 10; $i++) {
            $cb->recordFailure();
        }

        $this->assertTrue($cb->isAllowed());
    }

    #[Test]
    public function it_opens_after_failure_threshold(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 3,
            timeout: 30.0,
        );
        $cb = new CircuitBreaker($config);

        // Record failures
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());

        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());

        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
        $this->assertFalse($cb->isAllowed());
    }

    #[Test]
    public function it_resets_failure_count_on_success(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 3,
        );
        $cb = new CircuitBreaker($config);

        // Record some failures
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(2, $cb->getFailureCount());

        // Success should reset
        $cb->recordSuccess();
        $this->assertSame(0, $cb->getFailureCount());
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    #[Test]
    public function it_throws_when_circuit_is_open(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
            timeout: 30.0,
        );
        $cb = new CircuitBreaker($config);

        $cb->recordFailure(); // Opens circuit

        $this->expectException(CircuitBreakerOpenError::class);

        $cb->execute(fn () => 'should not execute');
    }

    #[Test]
    public function it_executes_callable_when_closed(): void
    {
        $cb = new CircuitBreaker(new CircuitBreakerConfig());

        $result = $cb->execute(fn () => 'success');

        $this->assertSame('success', $result);
    }

    #[Test]
    public function it_records_success_after_successful_execution(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 3,
        );
        $cb = new CircuitBreaker($config);

        // Record some failures first
        $cb->recordFailure();
        $cb->recordFailure();

        // Successful execution should reset
        $cb->execute(fn () => true);

        $this->assertSame(0, $cb->getFailureCount());
    }

    #[Test]
    public function it_records_failure_after_failed_execution(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 5,
        );
        $cb = new CircuitBreaker($config);

        try {
            $cb->execute(function (): void {
                throw new RuntimeException('Test error');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(1, $cb->getFailureCount());
    }

    #[Test]
    public function it_can_be_reset(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
        );
        $cb = new CircuitBreaker($config);

        $cb->recordFailure(); // Opens circuit
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        $cb->reset();

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertSame(0, $cb->getFailureCount());
        $this->assertTrue($cb->isAllowed());
    }

    #[Test]
    public function it_tracks_opened_at_time(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
        );
        $cb = new CircuitBreaker($config);

        $this->assertNull($cb->getOpenedAt());

        $before = microtime(true);
        $cb->recordFailure(); // Opens circuit
        $after = microtime(true);

        $openedAt = $cb->getOpenedAt();
        $this->assertNotNull($openedAt);
        $this->assertGreaterThanOrEqual($before, $openedAt);
        $this->assertLessThanOrEqual($after, $openedAt);
    }

    #[Test]
    public function it_transitions_to_half_open_after_timeout(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
            timeout: 0.01, // Very short timeout for testing
        );
        $cb = new CircuitBreaker($config);

        $cb->recordFailure(); // Opens circuit
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        // Wait for timeout
        usleep(15000); // 15ms

        // Should transition to half-open when we check state
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());
        $this->assertTrue($cb->isAllowed());
    }

    #[Test]
    public function it_closes_from_half_open_after_success_threshold(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
            successThreshold: 2,
            timeout: 0.01,
        );
        $cb = new CircuitBreaker($config);

        $cb->recordFailure(); // Opens circuit
        usleep(15000); // Wait for timeout

        // Now in half-open state
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // First success
        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Second success should close
        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    #[Test]
    public function it_reopens_from_half_open_on_failure(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
            successThreshold: 3,
            timeout: 0.01,
        );
        $cb = new CircuitBreaker($config);

        $cb->recordFailure(); // Opens circuit
        usleep(15000); // Wait for timeout

        // Now in half-open state
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Failure should reopen
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
    }

    #[Test]
    public function circuit_breaker_open_error_contains_details(): void
    {
        $config = new CircuitBreakerConfig(
            enabled: true,
            failureThreshold: 1,
            timeout: 30.0,
        );
        $cb = new CircuitBreaker($config);

        $cb->recordFailure(); // Opens circuit

        try {
            $cb->execute(fn () => 'should not execute');
            $this->fail('Expected CircuitBreakerOpenError');
        } catch (CircuitBreakerOpenError $e) {
            $this->assertSame('open', $e->state);
            $this->assertSame(1, $e->failureCount);
            $this->assertNotNull($e->openedAt);
            $this->assertSame(30.0, $e->timeout);
        }
    }
}
