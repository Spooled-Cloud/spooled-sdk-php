<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * One recorded lifecycle event of a job (`GET /jobs/{id}/history`).
 */
final readonly class JobHistoryEvent
{
    public function __construct(
        public string $id,
        public string $jobId,
        /**
         * What happened, e.g. `created`, `processing`, `completed`, `failed`,
         * `retried`, `deadlettered`. Kept as the string the API sent, so event
         * types added later still parse.
         */
        public string $eventType,
        /** Worker involved, when there was one. */
        public ?string $workerId,
        /** Event details: any JSON the server recorded (at most 64 KB). */
        public mixed $details,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $workerId = $data['workerId'] ?? $data['worker_id'] ?? null;
        $createdAt = $data['createdAt'] ?? $data['created_at'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            jobId: (string) ($data['jobId'] ?? $data['job_id'] ?? ''),
            eventType: (string) ($data['eventType'] ?? $data['event_type'] ?? ''),
            workerId: $workerId !== null && $workerId !== '' ? (string) $workerId : null,
            details: $data['details'] ?? null,
            createdAt: $createdAt !== null ? (string) $createdAt : null,
        );
    }
}
