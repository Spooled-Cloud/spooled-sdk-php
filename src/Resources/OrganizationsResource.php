<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Errors\UnsupportedOperationError;
use Spooled\Types\CreateOrganizationResponse;
use Spooled\Types\Organization;
use Spooled\Types\OrganizationList;
use Spooled\Types\OrganizationMember;
use Spooled\Types\OrganizationUsage;
use Spooled\Types\WebhookToken;

/**
 * Organizations resource for managing organizations.
 */
final class OrganizationsResource extends BaseResource
{
    /**
     * Create a new organization (public endpoint, no auth required).
     *
     * Returns the organization and the initial API key. The key is only
     * shown once; save `$result->apiKey->key`.
     *
     * @param array<string, mixed> $params {name, slug}
     */
    public function create(array $params): CreateOrganizationResponse
    {
        $response = $this->httpClient->post('organizations', $params);

        return CreateOrganizationResponse::fromArray($response);
    }

    /**
     * List organizations.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): OrganizationList
    {
        $response = $this->httpClient->get('organizations', $params);

        return OrganizationList::fromArray($response);
    }

    /**
     * Get an organization by ID.
     */
    public function get(string $orgId): Organization
    {
        $response = $this->httpClient->get("organizations/{$orgId}");

        return Organization::fromArray($response);
    }

    /**
     * Update an organization.
     *
     * @param array<string, mixed> $params
     */
    public function update(string $orgId, array $params): Organization
    {
        $response = $this->httpClient->put("organizations/{$orgId}", $params);

        return Organization::fromArray($response);
    }

    /**
     * Delete an organization.
     */
    public function delete(string $orgId): void
    {
        $this->httpClient->delete("organizations/{$orgId}");
    }

    /**
     * Get organization usage and limits (for current org based on auth).
     */
    public function getUsage(): OrganizationUsage
    {
        $response = $this->httpClient->get('organizations/usage');

        return OrganizationUsage::fromArray($response);
    }

    /**
     * Get organization members.
     *
     * @return array<OrganizationMember>
     */
    public function getMembers(string $orgId): array
    {
        $response = $this->httpClient->get("organizations/{$orgId}/members");
        $members = $response['members'] ?? $response ?? [];

        if (!is_array($members) || (isset($members['id']))) {
            $members = [];
        }

        return array_map(
            fn (array $item) => OrganizationMember::fromArray($item),
            $members,
        );
    }

    /**
     * Check slug availability (GET /organizations/check-slug?slug=).
     *
     * @return array{available: bool, valid: bool, error: ?string, suggestion: ?string}
     */
    public function checkSlug(string $slug): array
    {
        $response = $this->httpClient->get('organizations/check-slug', ['slug' => $slug]);

        $error = $response['error'] ?? null;
        $suggestion = $response['suggestion'] ?? null;

        return [
            'available' => (bool) ($response['available'] ?? false),
            'valid' => array_key_exists('valid', $response) ? (bool) $response['valid'] : true,
            'error' => is_string($error) ? $error : null,
            'suggestion' => is_string($suggestion) ? $suggestion : null,
        ];
    }

    /**
     * Generate a unique slug from an organization name.
     */
    public function generateSlug(string $name): string
    {
        $response = $this->httpClient->post('organizations/generate-slug', ['name' => $name]);

        return (string) ($response['slug'] ?? '');
    }

    /**
     * Get the webhook token for the current organization.
     *
     * This token is used to verify incoming webhook payloads from Spooled.
     */
    public function getWebhookToken(): WebhookToken
    {
        $response = $this->httpClient->get('organizations/webhook-token');

        return WebhookToken::fromArray($response);
    }

    /**
     * Regenerate the webhook token for the current organization.
     *
     * This invalidates the old token immediately.
     */
    public function regenerateWebhookToken(): WebhookToken
    {
        $response = $this->httpClient->post('organizations/webhook-token/regenerate');

        return WebhookToken::fromArray($response);
    }

    /**
     * Clear/delete the webhook token for the current organization.
     *
     * The route exists but its handler always answers 422, so clearing a token
     * has never worked.
     *
     * @deprecated Not supported by the Spooled API. Rotate the token with
     *             {@see self::regenerateWebhookToken()}.
     * @throws UnsupportedOperationError always, without a network call
     */
    public function clearWebhookToken(): void
    {
        throw new UnsupportedOperationError(
            'OrganizationsResource::clearWebhookToken',
            'the API cannot clear a webhook token (it always answers 422); use regenerateWebhookToken()',
        );
    }

    /**
     * Invite a member to an organization.
     *
     * Members are API keys; there are no user accounts to invite and no
     * `organizations/{id}/members/invite` route.
     *
     * @deprecated Not supported by the Spooled API. Create an API key instead
     *             ({@see ApiKeysResource::create()}).
     * @param array<string, mixed> $params {email, role}
     * @throws UnsupportedOperationError always, without a network call
     */
    public function inviteMember(string $orgId, array $params): OrganizationMember
    {
        unset($orgId, $params);

        throw new UnsupportedOperationError(
            'OrganizationsResource::inviteMember',
            'members are API keys, not invited users; create one with apiKeys->create()',
        );
    }

    /**
     * Remove a member from an organization.
     *
     * There is no DELETE /organizations/{id}/members/{memberId}. GET
     * /organizations/{id}/members is backed by active API keys (`id` is the
     * key id), so this revokes `DELETE /api-keys/{memberId}`.
     */
    public function removeMember(string $orgId, string $memberId): void
    {
        unset($orgId);
        $this->httpClient->delete("api-keys/{$memberId}");
    }

    /**
     * Update a member's role.
     *
     * A member's role is derived from its API key's `queues` scope; there is no
     * `PUT organizations/{id}/members/{memberId}` route.
     *
     * @deprecated Not supported by the Spooled API. Change the key's queue scope instead
     *             ({@see ApiKeysResource::update()}).
     * @param array<string, mixed> $params {role}
     * @throws UnsupportedOperationError always, without a network call
     */
    public function updateMemberRole(string $orgId, string $memberId, array $params): OrganizationMember
    {
        unset($orgId, $memberId, $params);

        throw new UnsupportedOperationError(
            'OrganizationsResource::updateMemberRole',
            'a member role is derived from its API key queue scope; update the key with apiKeys->update()',
        );
    }
}
