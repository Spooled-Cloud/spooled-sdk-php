<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Scripts;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Spooled\Resources\JobsResource;
use Spooled\Resources\SchedulesResource;
use Spooled\Resources\WorkflowsResource;
use Spooled\SpooledClient;
use Spooled\Tests\Support\HttpClientFactory;

/**
 * The clean-ups of scripts/test-local.php (scripts/lib/suite-cleanup.php) run
 * against whatever organization the API key belongs to. They must only touch
 * schedules, workflows and jobs that a run of the suite created, never the
 * organization's own ones - also not ones that merely start with `test-`.
 */
final class SuiteCleanupTest extends TestCase
{
    private const OWN = 'test-1760270000-1a2b3c4d';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/scripts/lib/suite-cleanup.php';
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @param array<mixed> $responses
     */
    private function client(array $responses): SpooledClient
    {
        $http = HttpClientFactory::create($responses, $this->history);

        $reflection = new ReflectionClass(SpooledClient::class);
        $client = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($client, $http);
        $reflection->getProperty('jobs')->setValue($client, new JobsResource($http));
        $reflection->getProperty('schedules')->setValue($client, new SchedulesResource($http));
        $reflection->getProperty('workflows')->setValue($client, new WorkflowsResource($http));

        return $client;
    }

    /**
     * The method and path of every request after the first (the list call).
     *
     * @return array<int, string>
     */
    private function writes(): array
    {
        $out = [];
        foreach (array_slice($this->history, 1) as $entry) {
            $request = $entry['request'];
            if ($request->getMethod() !== 'GET') {
                $out[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
            }
        }

        return $out;
    }

    #[Test]
    public function only_names_with_a_run_prefix_belong_to_the_suite(): void
    {
        $this->assertTrue(isSuiteOwnedName(generateTestId() . '-schedule'));
        $this->assertTrue(isSuiteOwnedName(self::OWN . '-workflows'));

        $this->assertFalse(isSuiteOwnedName(null));
        $this->assertFalse(isSuiteOwnedName(''));
        $this->assertFalse(isSuiteOwnedName('nightly-billing'));
        $this->assertFalse(isSuiteOwnedName('test-nightly-report'));
        $this->assertFalse(isSuiteOwnedName('test-payments'));
        $this->assertFalse(isSuiteOwnedName('test-1760270000-staging'));
        $this->assertFalse(isSuiteOwnedName(self::OWN), 'the suite always appends a suffix to its prefix');
        $this->assertFalse(isSuiteOwnedName('prod-' . self::OWN . '-x'));
    }

    #[Test]
    public function cleanup_schedules_deletes_only_the_suites_own_schedules(): void
    {
        $schedules = [
            ['id' => 'sch-billing', 'name' => 'nightly-billing', 'queue_name' => 'billing', 'cron_expression' => '0 0 * * * *'],
            ['id' => 'sch-test-named', 'name' => 'test-nightly-report', 'queue_name' => 'reports', 'cron_expression' => '0 0 * * * *'],
            ['id' => 'sch-test-queue', 'name' => self::OWN . '-lookalike', 'queue_name' => 'emails', 'cron_expression' => '0 0 * * * *'],
            ['id' => 'sch-own', 'name' => self::OWN . '-schedule', 'queue_name' => self::OWN . '-schedules', 'cron_expression' => '0 0 * * * *'],
        ];
        // One answer per schedule, so a clean-up that deletes everything gets
        // as far as deleting the organization's own schedules.
        $client = $this->client([
            $this->json($schedules),
            new Response(204),
            new Response(204),
            new Response(204),
            new Response(204),
        ]);

        $deleted = cleanupSchedules($client);

        $this->assertSame(['DELETE /api/v1/schedules/sch-own'], $this->writes());
        $this->assertSame(1, $deleted);
    }

    #[Test]
    public function cleanup_workflows_cancels_only_the_suites_own_unfinished_workflows(): void
    {
        $workflows = [
            ['id' => 'wf-orders', 'name' => 'order-pipeline', 'status' => 'running'],
            ['id' => 'wf-test-named', 'name' => 'test-import', 'status' => 'pending'],
            ['id' => 'wf-own-done', 'name' => self::OWN . '-workflow', 'status' => 'completed'],
            ['id' => 'wf-own', 'name' => self::OWN . '-dag-workflow', 'status' => 'running'],
        ];
        $cancelled = ['id' => 'x', 'name' => 'x', 'status' => 'cancelled'];
        $client = $this->client([
            $this->json($workflows),
            $this->json($cancelled),
            $this->json($cancelled),
            $this->json($cancelled),
            $this->json($cancelled),
        ]);

        $count = cleanupWorkflows($client);

        $this->assertSame(['POST /api/v1/workflows/wf-own/cancel'], $this->writes());
        $this->assertSame(1, $count);
    }

    #[Test]
    public function cancel_suite_jobs_cancels_only_jobs_in_the_suites_own_queues(): void
    {
        $jobs = [
            ['id' => 'job-emails', 'queue_name' => 'emails', 'status' => 'pending'],
            ['id' => 'job-test-queue', 'queue_name' => 'test-payments', 'status' => 'pending'],
            ['id' => 'job-own', 'queue_name' => self::OWN . '-jobs-crud', 'status' => 'pending'],
        ];
        $cancelledJob = ['id' => 'job-own', 'queue_name' => self::OWN . '-jobs-crud', 'status' => 'cancelled'];
        $client = $this->client([
            $this->json($jobs),
            new Response(204),
            $this->json($cancelledJob),
            new Response(204),
            $this->json($cancelledJob),
            new Response(204),
            $this->json($cancelledJob),
        ]);

        $count = cancelSuiteJobs($client);

        $this->assertSame(['DELETE /api/v1/jobs/job-own'], $this->writes());
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_failing_list_is_ignored_and_nothing_is_touched(): void
    {
        $client = $this->client([
            $this->json(['message' => 'boom'], 400),
            $this->json(['message' => 'boom'], 400),
        ]);

        $this->assertSame(0, cleanupSchedules($client));
        $this->assertSame(0, cleanupWorkflows($client));
        $this->assertCount(2, $this->history);
        foreach ($this->history as $entry) {
            $this->assertSame('GET', $entry['request']->getMethod());
        }
    }
}
