<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * A page of a job's history (`GET /jobs/{id}/history`).
 */
final readonly class JobHistoryList
{
    /** Events per page when the request names no `limit`. */
    public const DEFAULT_PAGE_SIZE = 100;

    /** Largest page the API returns; a bigger `limit` is lowered to this. */
    public const MAX_PAGE_SIZE = 500;

    /**
     * @param array<JobHistoryEvent> $events
     */
    public function __construct(
        public array $events,
        /** Whether another page follows (the API's `X-Has-More` header). */
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
        $items = isset($data[0]) || $data === [] ? $data : ($data['events'] ?? $data['data'] ?? []);
        $events = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $events[] = JobHistoryEvent::fromArray($item);
            }
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $requestedLimit ?? self::DEFAULT_PAGE_SIZE));

        return new self(
            events: $events,
            hasMore: $hasMoreHeader ?? (count($events) >= $pageSize),
        );
    }
}
