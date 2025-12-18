<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when authentication fails (401).
 */
final class AuthenticationError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Authentication failed',
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 401, $errorCode, $details, $requestId, $rawBody, $previous);
    }
}
