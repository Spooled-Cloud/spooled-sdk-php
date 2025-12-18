<?php

declare(strict_types=1);

namespace Spooled\Util;

use Psr\Log\LoggerInterface;
use Spooled\Config\RetryConfig;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\SpooledError;
use Throwable;

/**
 * Handles retry logic with exponential backoff.
 */
final class RetryHandler
{
    /** @var array<string> HTTP methods that are safe to retry */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** @var array<int> HTTP status codes that should be retried */
    private const RETRYABLE_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly RetryConfig $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Execute a callable with retry logic.
     *
     * @template T
     * @param callable(): T $fn
     * @param string $method HTTP method
     * @param bool $forceRetry Force retries even for non-idempotent methods
     * @return T
     */
    public function execute(callable $fn, string $method = 'GET', bool $forceRetry = false): mixed
    {
        $attempt = 0;
        $lastException = null;

        while (true) {
            try {
                return $fn();
            } catch (Throwable $e) {
                $lastException = $e;

                if (!$this->shouldRetry($e, $method, $attempt, $forceRetry)) {
                    throw $e;
                }

                $retryAfter = $this->getRetryAfter($e);
                $delay = $this->config->calculateDelay($attempt, $retryAfter);

                $this->logger?->warning('Request failed, retrying', [
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->config->maxRetries,
                    'delay' => $delay,
                    'error' => $e->getMessage(),
                ]);

                $this->sleep($delay);
                $attempt++;
            }
        }
    }

    /**
     * Check if a request should be retried.
     */
    public function shouldRetry(
        Throwable $error,
        string $method,
        int $attempt,
        bool $forceRetry = false,
    ): bool {
        // Check max retries
        if (!$this->config->shouldRetry($attempt)) {
            return false;
        }

        // Check if method is idempotent (or forced)
        if (!$forceRetry && !$this->isIdempotentMethod($method)) {
            return false;
        }

        // Check if error is retryable
        return $this->isRetryableError($error);
    }

    /**
     * Check if an HTTP method is idempotent.
     */
    public function isIdempotentMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true);
    }

    /**
     * Check if an error is retryable.
     */
    public function isRetryableError(Throwable $error): bool
    {
        // Network errors are retryable
        if ($error instanceof \GuzzleHttp\Exception\ConnectException) {
            return true;
        }

        // Timeout errors are retryable
        if ($error instanceof \GuzzleHttp\Exception\TransferException &&
            str_contains($error->getMessage(), 'timed out')) {
            return true;
        }

        // Check status code for SpooledError
        if ($error instanceof SpooledError) {
            return in_array($error->statusCode, self::RETRYABLE_STATUS_CODES, true);
        }

        // Check for Guzzle HTTP errors
        if ($error instanceof \GuzzleHttp\Exception\RequestException) {
            $response = $error->getResponse();
            if ($response !== null) {
                return in_array($response->getStatusCode(), self::RETRYABLE_STATUS_CODES, true);
            }

            // No response means connection failed - retryable
            return true;
        }

        return false;
    }

    /**
     * Get retry-after value from error.
     */
    private function getRetryAfter(Throwable $error): ?float
    {
        if ($error instanceof RateLimitError) {
            return $error->retryAfter;
        }

        if ($error instanceof \GuzzleHttp\Exception\RequestException) {
            $response = $error->getResponse();
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $value = $response->getHeader('Retry-After')[0];

                // Check if it's a date or seconds
                if (is_numeric($value)) {
                    return (float) $value;
                }

                // Parse as HTTP date
                $timestamp = strtotime($value);
                if ($timestamp !== false) {
                    return max(0, $timestamp - time());
                }
            }
        }

        return null;
    }

    /**
     * Sleep for the specified duration.
     */
    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
