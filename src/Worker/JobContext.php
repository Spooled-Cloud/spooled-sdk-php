<?php

declare(strict_types=1);

namespace Spooled\Worker;

/**
 * Job context passed to job handlers.
 *
 * Provides access to job data and worker utilities.
 */
final class JobContext
{
    /** @var array<string, mixed>|null */
    private ?array $result = null;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        /** Unique job identifier */
        public readonly string $jobId,
        /** Queue the job is from */
        public readonly string $queueName,
        /** Job payload data */
        public readonly array $payload,
        /** Current retry count */
        public readonly int $retryCount,
        /** Maximum retries allowed */
        public readonly int $maxRetries,
        /** Job metadata */
        public readonly array $metadata = [],
        /** Parent worker instance */
        private readonly ?SpooledWorker $worker = null,
    ) {
    }

    /**
     * Check if worker is shutting down.
     */
    public function isCancelled(): bool
    {
        if ($this->worker === null) {
            return false;
        }

        return $this->worker->getState() === 'stopping'
            || $this->worker->getState() === 'stopped';
    }

    /**
     * Check if worker is shutting down (alias for isCancelled).
     */
    public function isShuttingDown(): bool
    {
        return $this->isCancelled();
    }

    /**
     * Check if this is a retry attempt.
     */
    public function isRetry(): bool
    {
        return $this->retryCount > 0;
    }

    /**
     * Get remaining retries.
     */
    public function getRemainingRetries(): int
    {
        return max(0, $this->maxRetries - $this->retryCount);
    }

    /**
     * Check if this is the last retry.
     */
    public function isLastRetry(): bool
    {
        return $this->retryCount >= $this->maxRetries;
    }

    /**
     * Check if this is the last attempt (alias for isLastRetry).
     */
    public function isLastAttempt(): bool
    {
        return $this->isLastRetry();
    }

    /**
     * Get a value from the payload.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Check if the payload has a key.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->payload);
    }

    /**
     * Get a value from metadata.
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set job result.
     *
     * @param array<string, mixed> $result
     */
    public function setResult(array $result): void
    {
        $this->result = $result;
    }

    /**
     * Get job result.
     *
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * Report progress (placeholder for future implementation).
     */
    public function progress(int $percent, ?string $message = null): void
    {
        // Progress tracking could be implemented via heartbeat metadata
        // For now, this is a no-op
    }

    /**
     * Log a message through the worker.
     */
    public function log(string $level, string $message, mixed $context = null): void
    {
        // Logging through worker context
        // This could be enhanced to include job metadata
    }
}
