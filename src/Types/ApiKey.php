<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Represents an API key.
 */
final readonly class ApiKey
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public ?string $key,
        public bool $active,
        public ?string $organizationId,
        /** @var array<string>|null */
        public ?array $scopes,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
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
            prefix: (string) ($data['prefix'] ?? ''),
            key: isset($data['key']) ? (string) $data['key'] : null,
            active: (bool) ($data['active'] ?? true),
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            scopes: isset($data['scopes']) && is_array($data['scopes']) ? $data['scopes'] : null,
            expiresAt: isset($data['expiresAt']) ? (string) $data['expiresAt'] : null,
            lastUsedAt: isset($data['lastUsedAt']) ? (string) $data['lastUsedAt'] : null,
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) ? (string) $data['updatedAt'] : null,
        );
    }
}

/**
 * API key list response.
 */
final readonly class ApiKeyList
{
    /**
     * @param array<ApiKey> $apiKeys
     */
    public function __construct(
        public array $apiKeys,
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
        // Wrapped: { "apiKeys": [...], "total": 10 }
        // Raw: [ {...}, {...}, ... ] (the shape GET /api/v1/api-keys returns)
        $isRawArray = isset($data[0]) && is_array($data[0]);
        $apiKeysData = $isRawArray ? $data : ($data['apiKeys'] ?? $data['keys'] ?? $data['data'] ?? []);

        $apiKeys = array_map(
            fn (array $item) => ApiKey::fromArray($item),
            $apiKeysData,
        );

        return new self(
            apiKeys: $apiKeys,
            total: (int) ($data['total'] ?? count($apiKeys)),
        );
    }
}
