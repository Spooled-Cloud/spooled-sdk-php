<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when validation fails (422).
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
    ) {
        parent::__construct($message, 422, $errorCode, $details, $requestId, $rawBody, $previous);
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
