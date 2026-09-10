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
 * GET /auth/me — JWT session, not an email/password user.
 *
 * The API sends organization_id, api_key_id, queues, issued_at, expires_at,
 * and optional organization. It never sends id/email/name.
 */
final readonly class CurrentUserResponse
{
    /**
     * @param array<string> $queues
     */
    public function __construct(
        public string $organizationId,
        public string $apiKeyId,
        public array $queues,
        public ?string $issuedAt,
        public ?string $expiresAt,
        public ?Organization $organization = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $queues = $data['queues'] ?? [];
        $queueList = [];
        if (is_array($queues)) {
            foreach ($queues as $item) {
                if (is_string($item) && $item !== '') {
                    $queueList[] = $item;
                }
            }
        }

        $org = is_array($data['organization'] ?? null)
            ? Organization::fromArray($data['organization'])
            : null;

        return new self(
            organizationId: (string) ($data['organizationId'] ?? $data['organization_id'] ?? ''),
            apiKeyId: (string) ($data['apiKeyId'] ?? $data['api_key_id'] ?? ''),
            queues: $queueList,
            issuedAt: isset($data['issuedAt']) ? (string) $data['issuedAt']
                : (isset($data['issued_at']) ? (string) $data['issued_at'] : null),
            expiresAt: isset($data['expiresAt']) ? (string) $data['expiresAt']
                : (isset($data['expires_at']) ? (string) $data['expires_at'] : null),
            organization: $org,
        );
    }
}

/**
 * Email login start response (POST /auth/email/start).
 */
final readonly class EmailLoginStartResponse
{
    public function __construct(
        public bool $success,
        public ?string $message,
        public ?int $codeExpiresIn = null,
        public ?string $emailSentTo = null,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $emailSentTo = $data['emailSentTo'] ?? $data['email_sent_to'] ?? null;
        $expires = $data['codeExpiresIn'] ?? $data['code_expires_in'] ?? null;

        return new self(
            success: array_key_exists('success', $data) ? (bool) $data['success'] : true,
            message: isset($data['message']) ? (string) $data['message'] : null,
            codeExpiresIn: $expires !== null ? (int) $expires : null,
            emailSentTo: is_string($emailSentTo) ? $emailSentTo : null,
        );
    }
}

/**
 * POST /auth/validate — `{ valid, error?, claims? }`.
 *
 * Claims carry `org_id`, `api_key_id`, `queues`, `exp`. The API never sends
 * a nested `user` or top-level `organizationId`/`scopes`/`expiresAt`.
 */
final readonly class TokenValidation
{
    public function __construct(
        public bool $valid,
        public ?User $user,
        public ?string $organizationId,
        /**
         * Queue allowlist from `claims.queues` (empty = all). `$scopes` is the
         * existing SDK name; the API field is `queues`.
         *
         * @var array<string>|null
         */
        public ?array $scopes,
        public ?int $expiresAt,
        public ?string $error = null,
        public ?string $apiKeyId = null,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $claims = is_array($data['claims'] ?? null) ? $data['claims'] : [];
        $orgId = $data['organizationId']
            ?? $data['organization_id']
            ?? $claims['orgId']
            ?? $claims['org_id']
            ?? $claims['organizationId']
            ?? null;
        $apiKeyId = $claims['apiKeyId'] ?? $claims['api_key_id'] ?? $data['apiKeyId'] ?? null;
        $exp = $data['expiresAt'] ?? $data['expires_at'] ?? $claims['exp'] ?? null;
        $queues = $data['scopes'] ?? $claims['queues'] ?? null;
        $queueList = null;
        if (is_array($queues)) {
            $queueList = [];
            foreach ($queues as $item) {
                if (is_string($item) && $item !== '') {
                    $queueList[] = $item;
                }
            }
        }

        return new self(
            valid: (bool) ($data['valid'] ?? false),
            user: isset($data['user']) && is_array($data['user']) ? User::fromArray($data['user']) : null,
            organizationId: $orgId !== null ? (string) $orgId : null,
            scopes: $queueList,
            expiresAt: $exp !== null ? (int) $exp : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            apiKeyId: $apiKeyId !== null ? (string) $apiKeyId : null,
        );
    }
}

/**
 * Email availability check response (GET /auth/check-email).
 */
final readonly class EmailCheckResponse
{
    public function __construct(
        public bool $exists,
        public bool $canRegister,
        public ?string $provider = null,
        public bool $available = false,
        public bool $signupEnabled = true,
    ) {
    }

    /**
     * Create from API response.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $exists = (bool) ($data['exists'] ?? false);
        $available = array_key_exists('available', $data)
            ? (bool) $data['available']
            : !$exists;
        $signupEnabled = array_key_exists('signupEnabled', $data) || array_key_exists('signup_enabled', $data)
            ? (bool) ($data['signupEnabled'] ?? $data['signup_enabled'] ?? false)
            : true;
        $canRegister = array_key_exists('canRegister', $data)
            ? (bool) $data['canRegister']
            : ($available && $signupEnabled);

        return new self(
            exists: $exists,
            canRegister: $canRegister,
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
            available: $available,
            signupEnabled: $signupEnabled,
        );
    }
}

/**
 * POST /auth/email/verify — tagged `{type: login|signup, ...}`.
 *
 * Login sends access/refresh tokens. Signup (no account yet) sends
 * `signup_token` and never access_token; mapping that onto AuthTokens
 * left accessToken empty and dropped the token needed for signup/complete.
 */
final readonly class EmailVerifyResponse
{
    public function __construct(
        public string $type,
        public ?string $accessToken = null,
        public ?string $refreshToken = null,
        public ?string $tokenType = null,
        public ?int $expiresIn = null,
        public ?int $refreshExpiresIn = null,
        public ?string $signupToken = null,
        public ?string $email = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? '');
        if ($type === '') {
            $type = isset($data['signupToken']) || isset($data['signup_token']) ? 'signup' : 'login';
        }

        $access = $data['accessToken'] ?? $data['access_token'] ?? null;
        $refresh = $data['refreshToken'] ?? $data['refresh_token'] ?? null;
        $tokenType = $data['tokenType'] ?? $data['token_type'] ?? null;
        $expires = $data['expiresIn'] ?? $data['expires_in'] ?? null;
        $refreshExpires = $data['refreshExpiresIn'] ?? $data['refresh_expires_in'] ?? null;
        $signupToken = $data['signupToken'] ?? $data['signup_token'] ?? null;
        $email = $data['email'] ?? null;

        return new self(
            type: $type,
            accessToken: is_string($access) && $access !== '' ? $access : null,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            tokenType: is_string($tokenType) && $tokenType !== '' ? $tokenType : null,
            expiresIn: $expires !== null ? (int) $expires : null,
            refreshExpiresIn: $refreshExpires !== null ? (int) $refreshExpires : null,
            signupToken: is_string($signupToken) && $signupToken !== '' ? $signupToken : null,
            email: is_string($email) && $email !== '' ? $email : null,
        );
    }
}
