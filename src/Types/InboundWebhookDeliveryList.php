<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * A page of the inbound webhook log, newest first.
 */
final readonly class InboundWebhookDeliveryList
{
    /** Entries per page when the request names no `limit`. */
    public const DEFAULT_PAGE_SIZE = 50;

    /** Largest page the API returns; a bigger `limit` is lowered to this. */
    public const MAX_PAGE_SIZE = 100;

    /**
     * @param array<InboundWebhookDelivery> $deliveries
     */
    public function __construct(
        public array $deliveries,
        /**
         * Whether another page may follow: the `X-Has-More` header when the
         * response has one, else whether this page was full.
         */
        public bool $hasMore,
    ) {
    }

    /**
     * @param array<mixed> $data The bare array the API returns
     * @param int|null $requestedLimit The `limit` the request sent, if any
     * @param bool|null $hasMoreHeader The `X-Has-More` header, when the response carried it
     */
    public static function fromArray(array $data, ?int $requestedLimit = null, ?bool $hasMoreHeader = null): self
    {
        $items = isset($data[0]) || $data === [] ? $data : ($data['deliveries'] ?? $data['data'] ?? []);
        $deliveries = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $deliveries[] = InboundWebhookDelivery::fromArray($item);
            }
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $requestedLimit ?? self::DEFAULT_PAGE_SIZE));

        return new self(
            deliveries: $deliveries,
            hasMore: $hasMoreHeader ?? (count($deliveries) >= $pageSize),
        );
    }
}
