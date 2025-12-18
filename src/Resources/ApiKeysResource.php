<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Types\ApiKey;
use Spooled\Types\ApiKeyList;
use Spooled\Types\SuccessResponse;

/**
 * API Keys resource for managing API keys.
 */
final class ApiKeysResource extends BaseResource
{
    /**
     * List all API keys.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ApiKeyList
    {
        $response = $this->httpClient->get('api-keys', $params);

        return ApiKeyList::fromArray($response);
    }

    /**
     * Create a new API key.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params): ApiKey
    {
        $response = $this->httpClient->post('api-keys', $params);

        return ApiKey::fromArray($response);
    }

    /**
     * Get an API key by ID.
     */
    public function get(string $keyId): ApiKey
    {
        $response = $this->httpClient->get("api-keys/{$keyId}");

        return ApiKey::fromArray($response);
    }

    /**
     * Update an API key.
     *
     * @param array<string, mixed> $params
     */
    public function update(string $keyId, array $params): ApiKey
    {
        $response = $this->httpClient->put("api-keys/{$keyId}", $params);

        return ApiKey::fromArray($response);
    }

    /**
     * Delete an API key.
     */
    public function delete(string $keyId): SuccessResponse
    {
        $response = $this->httpClient->delete("api-keys/{$keyId}");

        return SuccessResponse::fromArray($response);
    }

    /**
     * Revoke an API key (same as delete).
     */
    public function revoke(string $keyId): SuccessResponse
    {
        return $this->delete($keyId);
    }

    /**
     * Regenerate an API key.
     */
    public function regenerate(string $keyId): ApiKey
    {
        $response = $this->httpClient->post("api-keys/{$keyId}/regenerate");

        return ApiKey::fromArray($response);
    }
}
