<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when validation fails.
 *
 * The production backend returns HTTP 400 (code VALIDATION_ERROR) for job and
 * queue validation failures, while other endpoints may return 422. Both map to
 * this error type, so the real HTTP status is preserved via $statusCode.
 */
final class ValidationError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Validation failed',
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
        int $statusCode = 422,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $details, $requestId, $rawBody, $previous);
    }

    /**
     * Get validation errors by field.
     *
     * @return array<string, array<string>>
     */
    public function getFieldErrors(): array
    {
        if (isset($this->details['fields']) && is_array($this->details['fields'])) {
            return $this->details['fields'];
        }

        return $this->details;
    }
}
