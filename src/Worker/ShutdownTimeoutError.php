<?php

declare(strict_types=1);

namespace Spooled\Worker;

use Error;

/**
 * Raised inside a running handler when the worker was asked to stop and
 * `shutdownTimeout` has elapsed without the handler finishing.
 *
 * Extends Error for the same reason as {@see JobTimeoutError}.
 */
final class ShutdownTimeoutError extends Error
{
}
