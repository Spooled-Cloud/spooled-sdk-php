<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown for server errors (5xx).
 */
final class ServerError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Server error',
        int $statusCode = 500,
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $details, $requestId, $rawBody, $previous);
    }
}
