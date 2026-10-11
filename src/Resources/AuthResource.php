<?php

declare(strict_types=1);

namespace Spooled\Resources;

use InvalidArgumentException;
use Spooled\Errors\UnsupportedOperationError;
use Spooled\Types\AuthTokens;
use Spooled\Types\CompleteSignupResponse;
use Spooled\Types\CurrentUserResponse;
use Spooled\Types\EmailCheckResponse;
use Spooled\Types\EmailLoginStartResponse;
use Spooled\Types\EmailVerifyResponse;
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
     *
     * POST /auth/email/verify is tagged: `{type: login, access_token, ...}`
     * for an existing account, or `{type: signup, signup_token, email}` when
     * no account exists yet. Mapping that onto AuthTokens dropped signup_token
     * and left accessToken empty.
     */
    public function emailVerify(string $email, string $code): EmailVerifyResponse
    {
        $response = $this->httpClient->post('auth/email/verify', [
            'email' => $email,
            'code' => $code,
        ]);

        return EmailVerifyResponse::fromArray($response);
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
     * Complete email signup after verify returned type signup.
     *
     * There is no POST /auth/register. The backend contract is
     * POST /auth/signup/complete with signupToken, name, and slug. The body
     * includes the one-time api_key as a string (not the POST /organizations
     * {id,key,name} object). Mapping that onto AuthTokens dropped the key.
     *
     * @param array<string, mixed> $params {signupToken, name, slug}
     */
    public function register(array $params): CompleteSignupResponse
    {
        $response = $this->httpClient->post('auth/signup/complete', $params);

        return CompleteSignupResponse::fromArray($response);
    }

    /**
     * Request password reset.
     *
     * Spooled has no password authentication, so there is no `auth/password/reset` route.
     *
     * @deprecated Not supported by the Spooled API. Sign in with an API key
     *             ({@see self::login()}) or an emailed code ({@see self::emailStart()}).
     * @throws UnsupportedOperationError always, without a network call
     */
    public function requestPasswordReset(string $email): SuccessResponse
    {
        unset($email);

        throw new UnsupportedOperationError(
            'AuthResource::requestPasswordReset',
            'the API has no password authentication; use auth->login() with an API key or auth->emailStart()',
        );
    }

    /**
     * Confirm password reset.
     *
     * Spooled has no password authentication, so there is no `auth/password/confirm` route.
     *
     * @deprecated Not supported by the Spooled API.
     * @throws UnsupportedOperationError always, without a network call
     */
    public function confirmPasswordReset(string $token, string $password): SuccessResponse
    {
        unset($token, $password);

        throw new UnsupportedOperationError(
            'AuthResource::confirmPasswordReset',
            'the API has no password authentication',
        );
    }

    /**
     * Change password.
     *
     * Spooled has no password authentication, so there is no `auth/password/change` route.
     *
     * @deprecated Not supported by the Spooled API. Rotate an API key instead
     *             ({@see ApiKeysResource::regenerate()}).
     * @throws UnsupportedOperationError always, without a network call
     */
    public function changePassword(string $currentPassword, string $newPassword): SuccessResponse
    {
        unset($currentPassword, $newPassword);

        throw new UnsupportedOperationError(
            'AuthResource::changePassword',
            'the API has no password authentication; rotate an API key with apiKeys->regenerate()',
        );
    }
}
