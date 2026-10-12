<?php

declare(strict_types=1);

namespace Spooled\Resources;

use DateTimeInterface;
use Spooled\Errors\UnsupportedOperationError;
use Spooled\Types\CustomWebhookResponse;
use Spooled\Types\InboundWebhookDeliveryList;
use Spooled\Util\ListQuery;

/**
 * Webhook ingestion resource for ingesting webhooks from various sources.
 */
final class WebhookIngestionResource extends BaseResource
{
    /**
     * Ingest a custom webhook (Node parity).
     *
     * POST /api/v1/webhooks/{org_id}/custom returns `{jobId, queueName, status}`.
     * An older empty 200 maps to null fields rather than throwing.
     *
     * @param array<string, mixed> $params
     * @param array{webhookToken?: string, forwardedProto?: string} $opts
     */
    public function custom(string $orgId, array $params, array $opts = []): CustomWebhookResponse
    {
        $headers = [];
        if (($opts['webhookToken'] ?? null) !== null) {
            $headers['X-Webhook-Token'] = (string) $opts['webhookToken'];
        }
        if (($opts['forwardedProto'] ?? null) !== null) {
            $headers['X-Forwarded-Proto'] = (string) $opts['forwardedProto'];
        }

        $response = $this->httpClient->post("webhooks/{$orgId}/custom", $params, headers: $headers);

        return CustomWebhookResponse::fromArray($response);
    }

    /**
     * The inbound webhook log: recent requests to `webhooks/{org_id}/custom`
     * that the API accepted, newest first (`GET /webhooks/deliveries`).
     *
     * Params: `status` (`created` or `duplicate`), `since` (RFC 3339 string or
     * `DateTimeInterface`: entries received at or after it), `queue` /
     * `queueName`, `limit` (1-100, default 50) and `offset`. An unknown status
     * or a `since` that is not RFC 3339 is a 400 (ValidationError). The log is
     * organization-wide, so a queue-scoped API key gets a 403. Entries carry
     * neither the payload nor any signature.
     *
     * @param array{
     *     status?: 'created'|'duplicate',
     *     since?: string|DateTimeInterface,
     *     queue?: string,
     *     queueName?: string,
     *     limit?: int,
     *     offset?: int
     * }|array<string, mixed> $params
     */
    public function listDeliveries(array $params = []): InboundWebhookDeliveryList
    {
        if (isset($params['queue']) && !isset($params['queueName'])) {
            $params['queueName'] = $params['queue'];
        }
        unset($params['queue']);

        $params = ListQuery::normalize($params);
        $response = $this->httpClient->get('webhooks/deliveries', $params);

        return InboundWebhookDeliveryList::fromArray(
            $response,
            isset($params['limit']) ? (int) $params['limit'] : null,
            ListQuery::hasMore($this->httpClient->getLastResponseHeader('X-Has-More')),
        );
    }

    /**
     * Ingest a GitHub webhook.
     *
     * The API only serves `POST /api/v1/webhooks/{org_id}/custom`; the
     * provider-specific `/github` handler was removed, so this always 404'd.
     *
     * @deprecated Not supported by the Spooled API. Forward the event to
     *             {@see self::custom()} (with `queue_name`) from your own endpoint;
     *             {@see self::validateGitHubSignature()} still checks GitHub signatures.
     * @param array{
     *   githubEvent: string,
     *   webhookToken?: string,
     *   forwardedProto?: string,
     *   signature?: string,
     *   secret?: string
     * } $options
     * @throws UnsupportedOperationError always, without a network call
     */
    public function github(
        string $orgId,
        string $body,
        array $options,
    ): void {
        unset($orgId, $body, $options);

        throw new UnsupportedOperationError(
            'WebhookIngestionResource::github',
            'the API only serves webhooks/{org_id}/custom; verify the GitHub signature yourself '
            . 'and forward the event with ingest->custom()',
        );
    }

    /**
     * Ingest a Stripe webhook.
     *
     * The API only serves `POST /api/v1/webhooks/{org_id}/custom`; the
     * provider-specific `/stripe` handler was removed, so this always 404'd.
     *
     * @deprecated Not supported by the Spooled API. Forward the event to
     *             {@see self::custom()} (with `queue_name`) from your own endpoint;
     *             {@see self::validateStripeSignature()} still checks Stripe signatures.
     * @param array{
     *   webhookToken?: string,
     *   forwardedProto?: string,
     *   signature?: string,
     *   secret?: string,
     *   timestamp?: int
     * } $options
     * @throws UnsupportedOperationError always, without a network call
     */
    public function stripe(
        string $orgId,
        string $body,
        array $options = [],
    ): void {
        unset($orgId, $body, $options);

        throw new UnsupportedOperationError(
            'WebhookIngestionResource::stripe',
            'the API only serves webhooks/{org_id}/custom; verify the Stripe signature yourself '
            . 'and forward the event with ingest->custom()',
        );
    }

    /**
     * Generate a GitHub webhook signature.
     */
    public function generateGitHubSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Validate a GitHub webhook signature.
     */
    public function validateGitHubSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = $this->generateGitHubSignature($payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Generate a Stripe webhook signature.
     */
    public function generateStripeSignature(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Validate a Stripe webhook signature.
     *
     * @param int $tolerance Tolerance in seconds (default: 300 = 5 minutes)
     */
    public function validateStripeSignature(
        string $payload,
        string $signature,
        string $secret,
        int $tolerance = 300,
    ): bool {
        // Parse signature header
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        if (!isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $providedSignature = $parts['v1'];

        // Check timestamp tolerance
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // Generate expected signature
        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    // NOTE: Previously this class exposed `ingest()` and queue-based helpers hitting `ingest/*`.
    // Those were not parity with Node/Python and are intentionally removed in favor of `/webhooks/{org_id}/*`.
}
