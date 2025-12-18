<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\BillingPortalResponse;
use Spooled\Types\BillingStatus;

/**
 * Billing resource for Stripe billing integration.
 */
final class BillingResource extends BaseResource
{
    /**
     * Get billing status for the authenticated organization.
     *
     * GET /api/v1/billing/status
     */
    public function getStatus(): BillingStatus
    {
        $response = $this->httpClient->get('billing/status');

        return BillingStatus::fromArray($response);
    }

    /**
     * Create a Stripe billing portal session.
     *
     * POST /api/v1/billing/portal
     *
     * @param array<string, mixed> $params Must include 'returnUrl'
     */
    public function createPortal(array $params): BillingPortalResponse
    {
        $response = $this->httpClient->post('billing/portal', $params);

        return BillingPortalResponse::fromArray($response);
    }
}
