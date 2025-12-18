<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Config\RetryConfig;

#[CoversClass(RetryConfig::class)]
final class RetryConfigTest extends TestCase
{
    #[Test]
    public function it_uses_default_values(): void
    {
        $config = new RetryConfig();

        $this->assertSame(RetryConfig::DEFAULT_MAX_RETRIES, $config->maxRetries);
        $this->assertSame(RetryConfig::DEFAULT_BASE_DELAY, $config->baseDelay);
        $this->assertSame(RetryConfig::DEFAULT_MAX_DELAY, $config->maxDelay);
        $this->assertSame(RetryConfig::DEFAULT_FACTOR, $config->factor);
        $this->assertSame(RetryConfig::DEFAULT_JITTER, $config->jitter);
    }

    #[Test]
    public function it_accepts_custom_values(): void
    {
        $config = new RetryConfig(
            maxRetries: 5,
            baseDelay: 0.5,
            maxDelay: 60.0,
            factor: 3.0,
            jitter: 0.2,
        );

        $this->assertSame(5, $config->maxRetries);
        $this->assertSame(0.5, $config->baseDelay);
        $this->assertSame(60.0, $config->maxDelay);
        $this->assertSame(3.0, $config->factor);
        $this->assertSame(0.2, $config->jitter);
    }

    #[Test]
    #[DataProvider('shouldRetryProvider')]
    public function it_determines_if_should_retry(int $attempt, int $maxRetries, bool $expected): void
    {
        $config = new RetryConfig(maxRetries: $maxRetries);

        $this->assertSame($expected, $config->shouldRetry($attempt));
    }

    public static function shouldRetryProvider(): array
    {
        return [
            'first attempt with 3 retries' => [0, 3, true],
            'second attempt with 3 retries' => [1, 3, true],
            'third attempt with 3 retries' => [2, 3, true],
            'fourth attempt with 3 retries' => [3, 3, false],
            'fifth attempt with 3 retries' => [4, 3, false],
            'any attempt with 0 retries' => [0, 0, false],
        ];
    }

    #[Test]
    public function it_calculates_exponential_delay(): void
    {
        $config = new RetryConfig(
            baseDelay: 1.0,
            factor: 2.0,
            jitter: 0.0, // Disable jitter for predictable testing
            maxDelay: 100.0,
        );

        $this->assertSame(1.0, $config->calculateDelay(0));   // 1 * 2^0 = 1
        $this->assertSame(2.0, $config->calculateDelay(1));   // 1 * 2^1 = 2
        $this->assertSame(4.0, $config->calculateDelay(2));   // 1 * 2^2 = 4
        $this->assertSame(8.0, $config->calculateDelay(3));   // 1 * 2^3 = 8
        $this->assertSame(16.0, $config->calculateDelay(4));  // 1 * 2^4 = 16
    }

    #[Test]
    public function it_clamps_delay_to_max(): void
    {
        $config = new RetryConfig(
            baseDelay: 1.0,
            factor: 2.0,
            jitter: 0.0,
            maxDelay: 10.0,
        );

        $this->assertSame(8.0, $config->calculateDelay(3));   // 1 * 2^3 = 8 (under max)
        $this->assertSame(10.0, $config->calculateDelay(4));  // 1 * 2^4 = 16 -> clamped to 10
        $this->assertSame(10.0, $config->calculateDelay(10)); // 1 * 2^10 = 1024 -> clamped to 10
    }

    #[Test]
    public function it_respects_retry_after_header(): void
    {
        $config = new RetryConfig(
            baseDelay: 1.0,
            factor: 2.0,
            jitter: 0.0,
            maxDelay: 100.0,
        );

        // Retry-After should override calculated delay
        $this->assertSame(30.0, $config->calculateDelay(0, 30.0));
        $this->assertSame(5.0, $config->calculateDelay(5, 5.0));
    }

    #[Test]
    public function it_clamps_retry_after_to_max_delay(): void
    {
        $config = new RetryConfig(
            baseDelay: 1.0,
            maxDelay: 30.0,
        );

        // Retry-After of 60 should be clamped to maxDelay of 30
        $this->assertSame(30.0, $config->calculateDelay(0, 60.0));
    }

    #[Test]
    public function it_adds_jitter_to_delay(): void
    {
        $config = new RetryConfig(
            baseDelay: 10.0,
            factor: 1.0,
            jitter: 0.1, // 10% jitter
            maxDelay: 100.0,
        );

        // With 10% jitter, delay should be between 9 and 11
        $delays = [];
        for ($i = 0; $i < 100; $i++) {
            $delays[] = $config->calculateDelay(0);
        }

        $min = min($delays);
        $max = max($delays);

        // Allow some tolerance for random variance
        $this->assertGreaterThanOrEqual(9.0, $min);
        $this->assertLessThanOrEqual(11.0, $max);
    }

    #[Test]
    public function it_creates_disabled_config(): void
    {
        $config = RetryConfig::disabled();

        $this->assertSame(0, $config->maxRetries);
        $this->assertFalse($config->shouldRetry(0));
    }
}
