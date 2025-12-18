<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Plan tier enumeration.
 */
enum PlanTier: string
{
    case FREE = 'free';
    case STARTER = 'starter';
    case PRO = 'pro';
    case ENTERPRISE = 'enterprise';
}

/**
 * Represents an organization.
 */
final readonly class Organization
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $plan,
        public ?string $email,
        public ?string $billingEmail,
        public ?string $stripeCustomerId,
        public ?string $stripeSubscriptionId,
        /** @var array<string, int>|null */
        public ?array $limits,
        /** @var array<string, int>|null */
        public ?array $usage,
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
            slug: (string) ($data['slug'] ?? ''),
            plan: (string) ($data['plan'] ?? 'free'),
            email: isset($data['email']) ? (string) $data['email'] : null,
            billingEmail: isset($data['billingEmail']) ? (string) $data['billingEmail'] : null,
            stripeCustomerId: isset($data['stripeCustomerId']) ? (string) $data['stripeCustomerId'] : null,
            stripeSubscriptionId: isset($data['stripeSubscriptionId']) ? (string) $data['stripeSubscriptionId'] : null,
            limits: is_array($data['limits'] ?? null) ? $data['limits'] : null,
            usage: is_array($data['usage'] ?? null) ? $data['usage'] : null,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) ? (string) $data['updatedAt'] : null,
        );
    }
}

/**
 * Organization list response.
 */
final readonly class OrganizationList
{
    /**
     * @param array<Organization> $organizations
     */
    public function __construct(
        public array $organizations,
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
        $organizations = array_map(
            fn (array $item) => Organization::fromArray($item),
            $data['organizations'] ?? $data['data'] ?? [],
        );

        return new self(
            organizations: $organizations,
            total: (int) ($data['total'] ?? count($organizations)),
        );
    }
}

/**
 * Usage item with current and limit values.
 */
final readonly class UsageItem
{
    public function __construct(
        public int $current,
        public ?int $limit,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            current: (int) ($data['current'] ?? 0),
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
        );
    }
}

/**
 * Organization limits.
 */
final readonly class OrganizationLimits
{
    public function __construct(
        public string $tier,
        public ?int $maxActiveJobs,
        public ?int $maxQueues,
        public ?int $maxWorkers,
        public ?int $maxSchedules,
        public ?int $maxWebhooks,
        public ?int $maxApiKeys,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tier: (string) ($data['tier'] ?? 'free'),
            maxActiveJobs: isset($data['max_active_jobs']) || isset($data['maxActiveJobs'])
                ? (int) ($data['max_active_jobs'] ?? $data['maxActiveJobs'])
                : null,
            maxQueues: isset($data['max_queues']) || isset($data['maxQueues'])
                ? (int) ($data['max_queues'] ?? $data['maxQueues'])
                : null,
            maxWorkers: isset($data['max_workers']) || isset($data['maxWorkers'])
                ? (int) ($data['max_workers'] ?? $data['maxWorkers'])
                : null,
            maxSchedules: isset($data['max_schedules']) || isset($data['maxSchedules'])
                ? (int) ($data['max_schedules'] ?? $data['maxSchedules'])
                : null,
            maxWebhooks: isset($data['max_webhooks']) || isset($data['maxWebhooks'])
                ? (int) ($data['max_webhooks'] ?? $data['maxWebhooks'])
                : null,
            maxApiKeys: isset($data['max_api_keys']) || isset($data['maxApiKeys'])
                ? (int) ($data['max_api_keys'] ?? $data['maxApiKeys'])
                : null,
        );
    }
}

/**
 * Organization usage breakdown.
 */
final readonly class UsageBreakdown
{
    public function __construct(
        public UsageItem $activeJobs,
        public UsageItem $queues,
        public UsageItem $workers,
        public UsageItem $schedules,
        public UsageItem $webhooks,
        public UsageItem $apiKeys,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            activeJobs: UsageItem::fromArray($data['activeJobs'] ?? $data['active_jobs'] ?? []),
            queues: UsageItem::fromArray($data['queues'] ?? []),
            workers: UsageItem::fromArray($data['workers'] ?? []),
            schedules: UsageItem::fromArray($data['schedules'] ?? []),
            webhooks: UsageItem::fromArray($data['webhooks'] ?? []),
            apiKeys: UsageItem::fromArray($data['apiKeys'] ?? $data['api_keys'] ?? []),
        );
    }
}

/**
 * Organization usage and limits.
 */
final readonly class OrganizationUsage
{
    public function __construct(
        public string $plan,
        public OrganizationLimits $limits,
        public UsageBreakdown $usage,
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
            plan: (string) ($data['plan'] ?? 'free'),
            limits: OrganizationLimits::fromArray($data['limits'] ?? []),
            usage: UsageBreakdown::fromArray($data['usage'] ?? []),
        );
    }
}

/**
 * Organization member.
 */
final readonly class OrganizationMember
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $organizationId,
        public string $role,
        public ?string $email,
        public ?string $name,
        public ?string $joinedAt,
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
            userId: (string) ($data['userId'] ?? ''),
            organizationId: (string) ($data['organizationId'] ?? ''),
            role: (string) ($data['role'] ?? 'member'),
            email: isset($data['email']) ? (string) $data['email'] : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            joinedAt: isset($data['joinedAt']) ? (string) $data['joinedAt'] : null,
        );
    }
}

/**
 * Webhook token for organization.
 */
final readonly class WebhookToken
{
    public function __construct(
        public string $token,
        public ?string $createdAt,
        public ?string $expiresAt,
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
            token: (string) ($data['token'] ?? $data['webhookToken'] ?? ''),
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            expiresAt: isset($data['expiresAt']) ? (string) $data['expiresAt'] : null,
        );
    }
}
