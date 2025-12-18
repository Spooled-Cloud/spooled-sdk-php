<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when there is a conflict (409).
 */
final class ConflictError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Resource conflict',
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 409, $errorCode, $details, $requestId, $rawBody, $previous);
    }
}
