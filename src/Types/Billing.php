<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Billing status returned by GET /api/v1/billing/status.
 */
final class BillingStatus
{
    public function __construct(
        /** Current plan tier (free/starter/pro/enterprise, etc.) */
        public readonly string $planTier,
        /** Whether the org has a Stripe customer object */
        public readonly bool $hasStripeCustomer,
        /** Stripe subscription ID (if subscribed) */
        public readonly ?string $stripeSubscriptionId = null,
        /** Stripe subscription status (active, past_due, canceled, etc.) */
        public readonly ?string $stripeSubscriptionStatus = null,
        /** Current billing period end (ISO8601) */
        public readonly ?string $stripeCurrentPeriodEnd = null,
        /** Whether the subscription will cancel at period end */
        public readonly ?bool $stripeCancelAtPeriodEnd = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            planTier: $data['plan_tier'] ?? $data['planTier'] ?? 'free',
            hasStripeCustomer: $data['has_stripe_customer'] ?? $data['hasStripeCustomer'] ?? false,
            stripeSubscriptionId: $data['stripe_subscription_id'] ?? $data['stripeSubscriptionId'] ?? null,
            stripeSubscriptionStatus: $data['stripe_subscription_status'] ?? $data['stripeSubscriptionStatus'] ?? null,
            stripeCurrentPeriodEnd: $data['stripe_current_period_end'] ?? $data['stripeCurrentPeriodEnd'] ?? null,
            stripeCancelAtPeriodEnd: $data['stripe_cancel_at_period_end'] ?? $data['stripeCancelAtPeriodEnd'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'planTier' => $this->planTier,
            'hasStripeCustomer' => $this->hasStripeCustomer,
            'stripeSubscriptionId' => $this->stripeSubscriptionId,
            'stripeSubscriptionStatus' => $this->stripeSubscriptionStatus,
            'stripeCurrentPeriodEnd' => $this->stripeCurrentPeriodEnd,
            'stripeCancelAtPeriodEnd' => $this->stripeCancelAtPeriodEnd,
        ], fn ($v) => $v !== null);
    }
}

/**
 * Response from creating a billing portal session.
 */
final class BillingPortalResponse
{
    public function __construct(
        /** URL to redirect the user to */
        public readonly string $url,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            url: $data['url'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
        ];
    }
}
