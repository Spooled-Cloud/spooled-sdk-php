<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when a request times out.
 */
final class TimeoutError extends SpooledError
{
    public function __construct(
        string $message = 'Request timed out',
        public readonly ?float $timeout = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, 'TIMEOUT', [], null, null, $previous);
    }
}
