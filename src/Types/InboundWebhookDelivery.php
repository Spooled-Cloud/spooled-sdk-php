<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * One entry of the inbound webhook log (`GET /webhooks/deliveries`): a request
 * to `POST /webhooks/{org_id}/custom` that the API accepted.
 *
 * The log never carries the stored payload or any signature.
 */
final readonly class InboundWebhookDelivery
{
    public function __construct(
        public string $id,
        /** Source of the request, e.g. `custom`. */
        public string $provider,
        /** The event type the request named. */
        public string $eventType,
        /** Queue the job was created in. */
        public ?string $matchedQueue,
        /** Job the request created or matched (null for entries before backend 0.1.122). */
        public ?string $jobId,
        /**
         * `created` (a new job) or `duplicate` (the idempotency key matched an
         * existing job); null for entries before backend 0.1.122. Kept as the
         * string the API sent.
         */
        public ?string $status,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            eventType: (string) ($data['eventType'] ?? $data['event_type'] ?? ''),
            matchedQueue: self::optionalString($data['matchedQueue'] ?? $data['matched_queue'] ?? null),
            jobId: self::optionalString($data['jobId'] ?? $data['job_id'] ?? null),
            status: self::optionalString($data['status'] ?? null),
            createdAt: self::optionalString($data['createdAt'] ?? $data['created_at'] ?? null),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
