<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown for network/connection failures.
 */
final class NetworkError extends SpooledError
{
    public function __construct(
        string $message = 'Network error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, 'NETWORK_ERROR', [], null, null, $previous);
    }
}
