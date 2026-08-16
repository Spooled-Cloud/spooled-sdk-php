<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\SuccessResponse;
use Spooled\Types\Webhook;
use Spooled\Types\WebhookDelivery;
use Spooled\Types\WebhookDeliveryList;
use Spooled\Types\WebhookList;

/**
 * Webhooks resource for managing outgoing webhooks.
 */
final class WebhooksResource extends BaseResource
{
    /**
     * List all webhooks.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): WebhookList
    {
        $response = $this->httpClient->get('outgoing-webhooks', $params);

        return WebhookList::fromArray($response);
    }

    /**
     * Create a new webhook.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params): Webhook
    {
        $response = $this->httpClient->post('outgoing-webhooks', $params);

        return Webhook::fromArray($response);
    }

    /**
     * Get a webhook by ID.
     */
    public function get(string $webhookId): Webhook
    {
        $response = $this->httpClient->get("outgoing-webhooks/{$webhookId}");

        return Webhook::fromArray($response);
    }

    /**
     * Update a webhook.
     *
     * The `secret` key is three-state, and passing it as null is destructive:
     *
     * - omit the key       - keep the current signing secret
     * - `'secret' => null` - CLEAR the secret; deliveries then go out unsigned,
     *                        with no `X-Spooled-Signature` header at all
     * - `'secret' => '..'` - replace the secret
     *
     * Build the params array from only the keys you mean to change. A params
     * array assembled from a form or config where an unset field becomes null
     * will silently disable HMAC signing, and a receiver that verifies
     * signatures will start rejecting every delivery.
     *
     * @param array<string, mixed> $params
     */
    public function update(string $webhookId, array $params): Webhook
    {
        $response = $this->httpClient->put("outgoing-webhooks/{$webhookId}", $params);

        return Webhook::fromArray($response);
    }

    /**
     * Delete a webhook.
     */
    public function delete(string $webhookId): SuccessResponse
    {
        $response = $this->httpClient->delete("outgoing-webhooks/{$webhookId}");

        return SuccessResponse::fromArray($response);
    }

    /**
     * Test a webhook.
     */
    public function test(string $webhookId): WebhookDelivery
    {
        $response = $this->httpClient->post("outgoing-webhooks/{$webhookId}/test");

        return WebhookDelivery::fromArray($response);
    }

    /**
     * Get webhook deliveries.
     *
     * Delivery history is retained, not permanent: rows are removed once they
     * pass the plan's history retention window (free 1 day, starter 7, pro 30,
     * enterprise 90), and only the newest 100 deliveries per webhook are
     * readable. Copy anything you need to keep into your own store.
     *
     * @param array<string, mixed> $params
     */
    public function getDeliveries(string $webhookId, array $params = []): WebhookDeliveryList
    {
        $response = $this->httpClient->get("outgoing-webhooks/{$webhookId}/deliveries", $params);

        return WebhookDeliveryList::fromArray($response);
    }

    /**
     * Retry a failed delivery.
     *
     * A successful manual retry resets the webhook's `failureCount` to 0. The
     * delivery must still be within the plan's history retention window; once
     * the row is swept there is nothing left to retry.
     */
    public function retryDelivery(string $webhookId, string $deliveryId): WebhookDelivery
    {
        $response = $this->httpClient->post("outgoing-webhooks/{$webhookId}/retry/{$deliveryId}");

        return WebhookDelivery::fromArray($response);
    }

    /**
     * Enable a webhook.
     *
     * This is also the recovery path for a webhook the platform disabled itself
     * after 20 consecutive failed deliveries (`lastStatus` of `auto_disabled`);
     * until it is enabled again it receives no events. Re-enabling is charged
     * against the plan webhook cap, so it can fail with HTTP 429 and error code
     * `QUOTA_EXCEEDED` (`Spooled\Errors\RateLimitError`).
     */
    public function enable(string $webhookId): Webhook
    {
        return $this->update($webhookId, ['enabled' => true]);
    }

    /**
     * Disable a webhook.
     *
     * Deliveries stop until the webhook is enabled again.
     */
    public function disable(string $webhookId): Webhook
    {
        return $this->update($webhookId, ['enabled' => false]);
    }
}
