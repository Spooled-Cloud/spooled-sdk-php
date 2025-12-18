<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Authentication tokens.
 */
final readonly class AuthTokens
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?int $expiresIn,
        public ?string $tokenType,
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
            accessToken: (string) ($data['accessToken'] ?? $data['access_token'] ?? ''),
            refreshToken: isset($data['refreshToken']) ? (string) $data['refreshToken'] : (isset($data['refresh_token']) ? (string) $data['refresh_token'] : null),
            expiresIn: isset($data['expiresIn']) ? (int) $data['expiresIn'] : (isset($data['expires_in']) ? (int) $data['expires_in'] : null),
            tokenType: isset($data['tokenType']) ? (string) $data['tokenType'] : (isset($data['token_type']) ? (string) $data['token_type'] : null),
        );
    }
}

/**
 * User information.
 */
final readonly class User
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $name,
        public ?string $avatarUrl,
        public ?string $organizationId,
        public ?string $role,
        public bool $emailVerified,
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
            email: (string) ($data['email'] ?? ''),
            name: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: isset($data['avatarUrl']) ? (string) $data['avatarUrl'] : null,
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            role: isset($data['role']) ? (string) $data['role'] : null,
            emailVerified: (bool) ($data['emailVerified'] ?? false),
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            updatedAt: isset($data['updatedAt']) ? (string) $data['updatedAt'] : null,
        );
    }
}

/**
 * Email login start response.
 */
final readonly class EmailLoginStartResponse
{
    public function __construct(
        public bool $success,
        public ?string $message,
        public ?int $codeExpiresIn,
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
            success: (bool) ($data['success'] ?? true),
            message: isset($data['message']) ? (string) $data['message'] : null,
            codeExpiresIn: isset($data['codeExpiresIn']) ? (int) $data['codeExpiresIn'] : null,
        );
    }
}

/**
 * Token validation response.
 */
final readonly class TokenValidation
{
    public function __construct(
        public bool $valid,
        public ?User $user,
        public ?string $organizationId,
        /** @var array<string>|null */
        public ?array $scopes,
        public ?int $expiresAt,
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
            valid: (bool) ($data['valid'] ?? false),
            user: isset($data['user']) && is_array($data['user']) ? User::fromArray($data['user']) : null,
            organizationId: isset($data['organizationId']) ? (string) $data['organizationId'] : null,
            scopes: isset($data['scopes']) && is_array($data['scopes']) ? $data['scopes'] : null,
            expiresAt: isset($data['expiresAt']) ? (int) $data['expiresAt'] : null,
        );
    }
}

/**
 * Email availability check response.
 */
final readonly class EmailCheckResponse
{
    public function __construct(
        public bool $exists,
        public bool $canRegister,
        public ?string $provider,
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
            exists: (bool) ($data['exists'] ?? false),
            canRegister: (bool) ($data['canRegister'] ?? true),
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
        );
    }
}
