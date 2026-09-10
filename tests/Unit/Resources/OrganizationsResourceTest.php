<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\OrganizationsResource;

#[CoversClass(OrganizationsResource::class)]
final class OrganizationsResourceTest extends TestCase
{
    #[Test]
    public function create_returns_organization_and_initial_api_key(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('organizations', ['name' => 'Acme', 'slug' => 'acme'])
            ->willReturn([
                'organization' => [
                    'id' => 'org_1',
                    'name' => 'Acme',
                    'slug' => 'acme',
                    'planTier' => 'free',
                    'createdAt' => '2024-01-01T00:00:00Z',
                    'updatedAt' => '2024-01-01T00:00:00Z',
                ],
                'apiKey' => [
                    'id' => 'key_1',
                    'key' => 'sp_live_abc123',
                    'name' => 'Default API Key',
                    'createdAt' => '2024-01-01T00:00:00Z',
                ],
            ]);

        $got = (new OrganizationsResource($httpClient))->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $this->assertSame('org_1', $got->organization->id);
        $this->assertSame('sp_live_abc123', $got->apiKey->key);
    }

    #[Test]
    public function check_slug_maps_suggestion_not_suggestions(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->with('organizations/check-slug', ['slug' => 'acme'])
            ->willReturn([
                'available' => false,
                'valid' => true,
                'suggestion' => 'acme-2',
            ]);

        $got = (new OrganizationsResource($httpClient))->checkSlug('acme');

        $this->assertFalse($got['available']);
        $this->assertTrue($got['valid']);
        $this->assertSame('acme-2', $got['suggestion']);
        $this->assertNull($got['error']);
        $this->assertArrayNotHasKey('suggestions', $got);
    }

    #[Test]
    public function remove_member_deletes_the_api_key_not_a_missing_members_route(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with('api-keys/key_1')
            ->willReturn([]);

        (new OrganizationsResource($httpClient))->removeMember('org_1', 'key_1');
    }
}
