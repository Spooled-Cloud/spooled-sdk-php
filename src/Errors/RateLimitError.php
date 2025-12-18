<?php

declare(strict_types=1);

namespace Spooled\Errors;

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
    ) {
        parent::__construct($message, 429, $errorCode, $details, $requestId, $rawBody, $previous);
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
