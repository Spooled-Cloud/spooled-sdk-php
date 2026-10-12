<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Spooled\Types\RateLimitInfo;
use Throwable;

/**
 * Error thrown when rate limit is exceeded (429).
 */
final class RateLimitError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Rate limit exceeded',
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        public readonly ?float $retryAfter = null,
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?int $reset = null,
        ?Throwable $previous = null,
        /** `X-RateLimit-Scope`: what the limit is counted against (e.g. `organization`). */
        public readonly ?string $scope = null,
    ) {
        parent::__construct($message, 429, $errorCode, $details, $requestId, $rawBody, $previous);
    }

    /**
     * True when this 429 is a plan limit / quota rejection rather than a rate limit.
     *
     * Quota errors (`QUOTA_EXCEEDED`, body `{"error":"limit_exceeded",...}`) do not
     * clear by waiting: retrying them only stalls the caller, and counting them
     * against the circuit breaker would lock acks out because enqueues hit the cap.
     * A 429 that carries `Retry-After` is a genuine rate limit, never a quota error
     * unless the API said so with the code.
     */
    public function isQuotaExceeded(): bool
    {
        if ($this->errorCode === 'QUOTA_EXCEEDED') {
            return true;
        }

        if ($this->retryAfter !== null || $this->rawBody === null || $this->rawBody === '') {
            return false;
        }

        $body = json_decode($this->rawBody, true);
        if (!is_array($body)) {
            return false;
        }

        // Older plan-limit bodies had no `code`; they always named the resource and plan.
        return ($body['error'] ?? null) === 'limit_exceeded'
            || (isset($body['resource'], $body['limit'], $body['plan']));
    }

    /**
     * The rate-limit headers of the 429 (`reset` is Unix time in seconds).
     */
    public function getRateLimitInfo(): RateLimitInfo
    {
        return new RateLimitInfo(
            limit: $this->limit,
            remaining: $this->remaining,
            reset: $this->reset,
            scope: $this->scope,
            retryAfter: $this->retryAfter,
        );
    }

    /**
     * Get recommended wait time before retry.
     */
    public function getRetryAfterSeconds(): float
    {
        if ($this->retryAfter !== null) {
            return $this->retryAfter;
        }

        if ($this->reset !== null) {
            return max(0, $this->reset - time());
        }

        // Default fallback
        return 60.0;
    }
}
