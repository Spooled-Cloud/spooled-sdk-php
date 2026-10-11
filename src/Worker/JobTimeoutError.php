<?php

declare(strict_types=1);

namespace Spooled\Worker;

use Error;

/**
 * Raised inside a running handler when the job's `timeout_seconds` has elapsed.
 *
 * It extends Error rather than Exception on purpose: handlers commonly wrap
 * risky calls in `catch (\Exception)` (retry loops, logging), and an abort that
 * can be swallowed by those would let the handler run on past its deadline.
 * Do not catch `\Throwable` in a handler without re-throwing this.
 */
final class JobTimeoutError extends Error
{
}
