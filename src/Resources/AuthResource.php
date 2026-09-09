<?php

declare(strict_types=1);

namespace Spooled\Resources;

use InvalidArgumentException;
use Spooled\Types\AuthTokens;
use Spooled\Types\CurrentUserResponse;
use Spooled\Types\EmailCheckResponse;
use Spooled\Types\EmailLoginStartResponse;
use Spooled\Types\SuccessResponse;
use Spooled\Types\TokenValidation;

/**
 * Auth resource for authentication operations.
 */
final class AuthResource extends BaseResource
{
    /**
     * Login with API key and get tokens.
     */
    public function login(string $apiKey): AuthTokens
    {
        $response = $this->httpClient->post('auth/login', [
            'apiKey' => $apiKey,
        ]);

        return AuthTokens::fromArray($response);
    }

    /**
     * Refresh access token.
     */
    public function refresh(string $refreshToken): AuthTokens
    {
        $response = $this->httpClient->post('auth/refresh', [
            'refreshToken' => $refreshToken,
        ]);

        return AuthTokens::fromArray($response);
    }

    /**
     * Logout and invalidate tokens.
     *
     * POST /auth/logout blacklists the access token from the Authorization
     * header. The refresh token must be in the body or the session survives
     * via /auth/refresh. When omitted, the client's stored refresh token is
     * sent (same as the Node SDK).
     */
    public function logout(?string $refreshToken = null): SuccessResponse
    {
        $token = $refreshToken ?? $this->httpClient->getRefreshToken();
        $body = ($token !== null && $token !== '') ? ['refreshToken' => $token] : null;
        $response = $this->httpClient->post('auth/logout', $body);

        return SuccessResponse::fromArray($response);
    }

    /**
     * Get the current JWT session (GET /auth/me).
     *
     * The body is organization_id / api_key_id / queues, not an email user.
     */
    public function me(): CurrentUserResponse
    {
        $response = $this->httpClient->get('auth/me');

        return CurrentUserResponse::fromArray($response);
    }

    /**
     * Validate a JWT token.
     *
     * @param string|null $token Token to validate (uses current token if not provided)
     */
    public function validate(?string $token = null): TokenValidation
    {
        // Get token from client if not provided
        $tokenToValidate = $token ?? $this->httpClient->getAccessToken();

        if ($tokenToValidate === null) {
            throw new InvalidArgumentException('No token provided for validation');
        }

        $response = $this->httpClient->post('auth/validate', ['token' => $tokenToValidate]);

        return TokenValidation::fromArray($response);
    }

    /**
     * Start email login flow.
     */
    public function emailStart(string $email): EmailLoginStartResponse
    {
        $response = $this->httpClient->post('auth/email/start', [
            'email' => $email,
        ]);

        return EmailLoginStartResponse::fromArray($response);
    }

    /**
     * Verify email login code.
     */
    public function emailVerify(string $email, string $code): AuthTokens
    {
        $response = $this->httpClient->post('auth/email/verify', [
            'email' => $email,
            'code' => $code,
        ]);

        return AuthTokens::fromArray($response);
    }

    /**
     * Check if email exists.
     */
    public function checkEmail(string $email): EmailCheckResponse
    {
        $response = $this->httpClient->get('auth/check-email', ['email' => $email]);

        return EmailCheckResponse::fromArray($response);
    }

    /**
     * Register a new user.
     *
     * @param array<string, mixed> $params
     */
    public function register(array $params): AuthTokens
    {
        $response = $this->httpClient->post('auth/register', $params);

        return AuthTokens::fromArray($response);
    }

    /**
     * Request password reset.
     */
    public function requestPasswordReset(string $email): SuccessResponse
    {
        $response = $this->httpClient->post('auth/password/reset', [
            'email' => $email,
        ]);

        return SuccessResponse::fromArray($response);
    }

    /**
     * Confirm password reset.
     */
    public function confirmPasswordReset(string $token, string $password): SuccessResponse
    {
        $response = $this->httpClient->post('auth/password/confirm', [
            'token' => $token,
            'password' => $password,
        ]);

        return SuccessResponse::fromArray($response);
    }

    /**
     * Change password.
     */
    public function changePassword(string $currentPassword, string $newPassword): SuccessResponse
    {
        $response = $this->httpClient->post('auth/password/change', [
            'currentPassword' => $currentPassword,
            'newPassword' => $newPassword,
        ]);

        return SuccessResponse::fromArray($response);
    }
}
