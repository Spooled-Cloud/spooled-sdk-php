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
     * @param array<string, mixed> $params
     */
    public function getDeliveries(string $webhookId, array $params = []): WebhookDeliveryList
    {
        $response = $this->httpClient->get("outgoing-webhooks/{$webhookId}/deliveries", $params);

        return WebhookDeliveryList::fromArray($response);
    }

    /**
     * Retry a failed delivery.
     */
    public function retryDelivery(string $webhookId, string $deliveryId): WebhookDelivery
    {
        $response = $this->httpClient->post("outgoing-webhooks/{$webhookId}/retry/{$deliveryId}");

        return WebhookDelivery::fromArray($response);
    }

    /**
     * Enable a webhook.
     */
    public function enable(string $webhookId): Webhook
    {
        $response = $this->httpClient->post("outgoing-webhooks/{$webhookId}/enable");

        return Webhook::fromArray($response);
    }

    /**
     * Disable a webhook.
     */
    public function disable(string $webhookId): Webhook
    {
        $response = $this->httpClient->post("outgoing-webhooks/{$webhookId}/disable");

        return Webhook::fromArray($response);
    }
}
