<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Webhook delivery status.
 */
enum WebhookDeliveryStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}

/**
 * Represents an outgoing webhook.
 */
final readonly class Webhook
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public bool $enabled,
        /** @var array<string> */
        public array $events,
        public ?string $organizationId,
        /**
         * Always null: the API never returns the signing secret, on any endpoint.
         * Null here therefore says nothing about whether deliveries are signed.
         * To answer that, track what you last sent to `webhooks->update()`.
         */
        public ?string $secret,
        public int $maxRetries,
        public ?int $timeout,
        /** @var array<string, string>|null */
        public ?array $headers,
        public int $deliveryCount,
        /**
         * Consecutive failed deliveries. Counted once per delivery, not once per
         * retry attempt, so the same amount of breakage produces a number roughly
         * 5x smaller than a per-attempt count. A successful delivery resets it to
         * 0, including a successful manual retry. At 20 the webhook is disabled
         * automatically and `$lastStatus` becomes `auto_disabled`.
         */
        public int $failureCount,
        /**
         * Outcome of the most recent delivery: `success`, `failed`, or
         * `auto_disabled` when the webhook was disabled after 20 consecutive
         * failed deliveries. Null before the first delivery.
         */
        public ?string $lastStatus,
        public ?string $lastDeliveryAt,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            enabled: (bool) ($data['enabled'] ?? true),
            events: is_array($data['events'] ?? null) ? $data['events'] : [],
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            secret: isset($data['secret']) ? (string) $data['secret'] : null,
            maxRetries: (int) ($data['maxRetries'] ?? 3),
            timeout: isset($data['timeout']) ? (int) $data['timeout'] : null,
            headers: is_array($data['headers'] ?? null) ? $data['headers'] : null,
            deliveryCount: (int) ($data['deliveryCount'] ?? 0),
            failureCount: (int) ($data['failureCount'] ?? $data['failure_count'] ?? 0),
            lastStatus: isset($data['lastStatus']) ? (string) $data['lastStatus'] : null,
            lastDeliveryAt: isset($data['lastTriggeredAt'])
                ? (string) $data['lastTriggeredAt']
                : (isset($data['lastDeliveryAt']) ? (string) $data['lastDeliveryAt'] : null),
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) ? (string) $data['updatedAt'] : null,
        );
    }
}

/**
 * Webhook list response.
 */
final readonly class WebhookList
{
    /**
     * @param array<Webhook> $webhooks
     */
    public function __construct(
        public array $webhooks,
        public int $total,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Handle both wrapped and raw array responses.
        // Wrapped: { "webhooks": [...], "total": 10 }
        // Raw: [ {...}, {...}, ... ] (the shape GET /api/v1/outgoing-webhooks returns)
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $webhooksData = $isRawArray ? $data : ($data['webhooks'] ?? $data['data'] ?? []);

        $webhooks = array_map(
            fn (array $item) => Webhook::fromArray($item),
            $webhooksData,
        );

        return new self(
            webhooks: $webhooks,
            total: (int) ($data['total'] ?? count($webhooks)),
        );
    }
}

/**
 * Webhook delivery record.
 */
final readonly class WebhookDelivery
{
    public function __construct(
        public string $id,
        public string $webhookId,
        public string $eventType,
        public string $status,
        public int $statusCode,
        public int $attemptNumber,
        /** @var array<string, mixed> */
        public array $payload,
        public ?string $response,
        public ?string $error,
        public ?float $duration,
        public ?string $createdAt,
        public ?string $deliveredAt,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            webhookId: (string) ($data['webhookId'] ?? ''),
            eventType: (string) ($data['eventType'] ?? $data['event'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            statusCode: (int) ($data['statusCode'] ?? 0),
            attemptNumber: (int) ($data['attemptNumber'] ?? $data['attempt'] ?? 1),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            response: isset($data['response']) ? (string) $data['response'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            deliveredAt: isset($data['deliveredAt']) ? (string) $data['deliveredAt'] : null,
        );
    }
}

/**
 * Webhook delivery list response.
 */
final readonly class WebhookDeliveryList
{
    /**
     * @param array<WebhookDelivery> $deliveries
     */
    public function __construct(
        public array $deliveries,
        public int $total,
        public int $page,
        public int $pageSize,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Handle both wrapped and raw array responses.
        // Wrapped: { "deliveries": [...], "total": 10, ... }
        // Raw: [ {...}, {...}, ... ] (the shape GET /api/v1/outgoing-webhooks/{id}/deliveries returns)
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $deliveriesData = $isRawArray ? $data : ($data['deliveries'] ?? $data['data'] ?? []);

        $deliveries = array_map(
            fn (array $item) => WebhookDelivery::fromArray($item),
            $deliveriesData,
        );

        return new self(
            deliveries: $deliveries,
            total: (int) ($data['total'] ?? count($deliveries)),
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['pageSize'] ?? $data['limit'] ?? count($deliveries)),
        );
    }
}
