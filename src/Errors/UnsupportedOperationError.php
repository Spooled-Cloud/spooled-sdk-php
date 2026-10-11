<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Thrown by SDK methods whose backend route does not exist.
 *
 * The method is kept (marked `@deprecated`) so callers fail loudly with an
 * explanation instead of a silent 404/405 from a route that was never served.
 * No network call is made before this is thrown.
 */
final class UnsupportedOperationError extends SpooledError
{
    public function __construct(
        /** Fully qualified method name, e.g. "AuthResource::changePassword" */
        public readonly string $operation,
        string $reason = '',
        ?Throwable $previous = null,
    ) {
        $message = "{$operation} is not supported by the Spooled API";
        if ($reason !== '') {
            $message .= ": {$reason}";
        }

        parent::__construct($message, 0, 'UNSUPPORTED_OPERATION', [], null, null, $previous);
    }
}
