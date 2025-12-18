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
        public ?string $secret,
        public int $maxRetries,
        public ?int $timeout,
        /** @var array<string, string>|null */
        public ?array $headers,
        public int $deliveryCount,
        public int $failedCount,
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
            failedCount: (int) ($data['failedCount'] ?? 0),
            lastDeliveryAt: isset($data['lastDeliveryAt']) ? (string) $data['lastDeliveryAt'] : null,
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
        $webhooks = array_map(
            fn (array $item) => Webhook::fromArray($item),
            $data['webhooks'] ?? $data['data'] ?? [],
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
        $deliveries = array_map(
            fn (array $item) => WebhookDelivery::fromArray($item),
            $data['deliveries'] ?? $data['data'] ?? [],
        );

        return new self(
            deliveries: $deliveries,
            total: (int) ($data['total'] ?? count($deliveries)),
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['pageSize'] ?? $data['limit'] ?? count($deliveries)),
        );
    }
}
