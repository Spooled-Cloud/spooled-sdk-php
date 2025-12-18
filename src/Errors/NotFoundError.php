<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when a resource is not found (404).
 */
final class NotFoundError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Resource not found',
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 404, $errorCode, $details, $requestId, $rawBody, $previous);
    }
}
