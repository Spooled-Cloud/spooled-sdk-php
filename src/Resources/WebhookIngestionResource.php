<?php

declare(strict_types=1);

namespace Spooled\Resources;

use InvalidArgumentException;
use Spooled\Errors\ValidationError;

/**
 * Webhook ingestion resource for ingesting webhooks from various sources.
 */
final class WebhookIngestionResource extends BaseResource
{
    /**
     * Ingest a custom webhook (Node parity).
     *
     * POST /api/v1/webhooks/{org_id}/custom
     *
     * @param array<string, mixed> $params
     * @param array{webhookToken?: string, forwardedProto?: string} $opts
     */
    public function custom(string $orgId, array $params, array $opts = []): void
    {
        $headers = [];
        if (($opts['webhookToken'] ?? null) !== null) {
            $headers['X-Webhook-Token'] = (string) $opts['webhookToken'];
        }
        if (($opts['forwardedProto'] ?? null) !== null) {
            $headers['X-Forwarded-Proto'] = (string) $opts['forwardedProto'];
        }

        $this->httpClient->post("webhooks/{$orgId}/custom", $params, headers: $headers);
    }

    /**
     * Ingest a GitHub webhook.
     *
     * POST /api/v1/webhooks/{org_id}/github
     *
     * @param array{
     *   githubEvent: string,
     *   webhookToken?: string,
     *   forwardedProto?: string,
     *   signature?: string,
     *   secret?: string
     * } $options
     */
    public function github(
        string $orgId,
        string $body,
        array $options,
    ): void {
        $signature = $options['signature'] ?? null;
        $secret = $options['secret'] ?? null;
        if ($signature === null && $secret !== null) {
            $signature = $this->generateGitHubSignature($body, (string) $secret);
        }
        if ($signature === null) {
            throw new ValidationError('GitHub webhook signature is required (provide `signature` or `secret`)');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-GitHub-Event' => (string) ($options['githubEvent'] ?? ''),
            'X-Hub-Signature-256' => (string) $signature,
        ];

        if ($headers['X-GitHub-Event'] === '') {
            throw new ValidationError('GitHub webhook requires `githubEvent` option (X-GitHub-Event)');
        }

        if (($options['webhookToken'] ?? null) !== null) {
            $headers['X-Webhook-Token'] = (string) $options['webhookToken'];
        }
        if (($options['forwardedProto'] ?? null) !== null) {
            $headers['X-Forwarded-Proto'] = (string) $options['forwardedProto'];
        }

        $this->httpClient->postRaw("webhooks/{$orgId}/github", $body, headers: $headers);
    }

    /**
     * Ingest a Stripe webhook.
     *
     * POST /api/v1/webhooks/{org_id}/stripe
     *
     * @param array{
     *   webhookToken?: string,
     *   forwardedProto?: string,
     *   signature?: string,
     *   secret?: string,
     *   timestamp?: int
     * } $options
     */
    public function stripe(
        string $orgId,
        string $body,
        array $options = [],
    ): void {
        $signature = $options['signature'] ?? null;
        $secret = $options['secret'] ?? null;
        $timestamp = $options['timestamp'] ?? null;
        if ($signature === null && $secret !== null) {
            $signature = $this->generateStripeSignature($body, (string) $secret, $timestamp !== null ? (int) $timestamp : null);
        }
        if ($signature === null) {
            throw new ValidationError('Stripe webhook signature is required (provide `signature` or `secret`)');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Stripe-Signature' => (string) $signature,
        ];

        if (($options['webhookToken'] ?? null) !== null) {
            $headers['X-Webhook-Token'] = (string) $options['webhookToken'];
        }
        if (($options['forwardedProto'] ?? null) !== null) {
            $headers['X-Forwarded-Proto'] = (string) $options['forwardedProto'];
        }

        $this->httpClient->postRaw("webhooks/{$orgId}/stripe", $body, headers: $headers);
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
