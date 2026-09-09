<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\AdminResource;

#[CoversClass(AdminResource::class)]
final class AdminResourceTest extends TestCase
{
    #[Test]
    public function update_organization_patches_not_puts(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('patch')
            ->with(
                'admin/organizations/org_1',
                ['planTier' => 'enterprise'],
                [],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([
                'id' => 'org_1',
                'name' => 'Acme',
                'planTier' => 'enterprise',
            ]);
        $httpClient->expects($this->never())->method('put');

        $got = (new AdminResource($httpClient, 'adminkey'))
            ->updateOrganization('org_1', ['planTier' => 'enterprise']);

        $this->assertSame('org_1', $got->id);
        $this->assertSame('enterprise', $got->plan);
    }

    #[Test]
    public function delete_organization_sends_hard_delete_query(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('delete')
            ->with(
                'admin/organizations/org_1',
                ['hard_delete' => 'true'],
                ['X-Admin-Key' => 'adminkey'],
            )
            ->willReturn([]);

        $got = (new AdminResource($httpClient, 'adminkey'))
            ->deleteOrganization('org_1', true);

        $this->assertTrue($got->success);
    }
}
