<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\DashboardResource;

#[CoversClass(DashboardResource::class)]
final class DashboardResourceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function dashboardPayload(): array
    {
        return [
            'system' => ['version' => '0.1.111'],
            'jobs' => ['total' => 10, 'completed24h' => 4],
            'queues' => [],
            'workers' => ['total' => 2, 'healthy' => 1],
            'recentActivity' => ['jobsCreated1h' => 3, 'jobsCompleted1h' => 2],
        ];
    }

    #[Test]
    public function overview_and_slices_use_get_dashboard_not_missing_subpaths(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->exactly(3))
            ->method('get')
            ->with('dashboard', [])
            ->willReturn($this->dashboardPayload());

        $resource = new DashboardResource($httpClient);

        $overview = $resource->getOverview();
        $this->assertSame('0.1.111', $overview['system']['version']);

        $activity = $resource->getRecentActivity();
        $this->assertSame(3, $activity['jobsCreated1h']);

        $jobs = $resource->getJobCharts();
        $this->assertSame(10, $jobs['total']);
    }
}
