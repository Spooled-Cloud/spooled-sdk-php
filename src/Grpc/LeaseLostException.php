<?php

declare(strict_types=1);

namespace Spooled\Grpc;

use RuntimeException;

/**
 * A unary gRPC Complete, Fail, or RenewLease found that this worker no longer
 * holds the job's lease (FAILED_PRECONDITION: the lease expired, or the lease
 * id does not match). It extends RuntimeException, the type these calls have
 * always thrown, so existing handlers still catch it; `errorCode` is the
 * `LEASE_EXPIRED` code REST uses for the same case. Do not retry settlement
 * with the old lease id.
 */
final class LeaseLostException extends RuntimeException
{
    public const ERROR_CODE = 'LEASE_EXPIRED';

    public string $errorCode = self::ERROR_CODE;
}
