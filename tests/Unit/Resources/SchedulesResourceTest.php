<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\SchedulesResource;
use Spooled\Util\Casing;

#[CoversClass(SchedulesResource::class)]
final class SchedulesResourceTest extends TestCase
{
    /**
     * Capture the body passed to HttpClient::post and return a stubbed response.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed>|null $captured
     */
    private function makeResource(array $response, ?array &$captured): SchedulesResource
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturnCallback(
            function (...$args) use (&$captured, $response): array {
                $captured = $args[1] ?? null;

                return $response;
            },
        );

        return new SchedulesResource($httpClient);
    }

    /**
     * The create response shape the backend actually returns: a partial object
     * with only id/name/cron_expression/next_run_at (already camelCased by the
     * HTTP layer). It never echoes timezone, queue_name or payload_template.
     *
     * @return array<string, mixed>
     */
    private function partialCreateResponse(): array
    {
        return [
            'id' => 'sch_123',
            'name' => 'Daily Report',
            'cronExpression' => '0 9 * * *',
            'nextRunAt' => '2026-07-09T09:00:00Z',
        ];
    }

    #[Test]
    public function create_maps_documented_aliases_to_api_field_names(): void
    {
        $captured = null;
        $resource = $this->makeResource($this->partialCreateResponse(), $captured);

        // The exact params promised by the README "Schedules" example.
        $resource->create([
            'name' => 'Daily Report',
            'queue' => 'reports',
            'schedule' => '0 9 * * *',
            'payload' => ['type' => 'daily'],
            'timezone' => 'America/New_York',
        ]);

        $this->assertIsArray($captured);

        // The HTTP layer snake-cases the canonical camelCase keys before they go
        // over the wire; assert on that wire representation.
        $body = Casing::keysToSnakeCase($captured);

        $this->assertArrayHasKey('queue_name', $body);
        $this->assertSame('reports', $body['queue_name']);

        $this->assertArrayHasKey('cron_expression', $body);
        $this->assertSame('0 9 * * *', $body['cron_expression']);

        $this->assertArrayHasKey('payload_template', $body);
        $this->assertSame(['type' => 'daily'], $body['payload_template']);

        // The documented aliases must not leak through unmapped (the API would
        // reject them with HTTP 422).
        $this->assertArrayNotHasKey('queue', $body);
        $this->assertArrayNotHasKey('schedule', $body);
        $this->assertArrayNotHasKey('payload', $body);
    }

    #[Test]
    public function create_accepts_the_cron_alias(): void
    {
        $captured = null;
        $resource = $this->makeResource($this->partialCreateResponse(), $captured);

        $resource->create([
            'name' => 'Daily Report',
            'queue' => 'reports',
            'cron' => '*/5 * * * *',
            'payload' => ['type' => 'daily'],
        ]);

        $this->assertIsArray($captured);
        $body = Casing::keysToSnakeCase($captured);

        $this->assertSame('*/5 * * * *', $body['cron_expression']);
        $this->assertArrayNotHasKey('cron', $body);
    }

    #[Test]
    public function create_still_works_with_canonical_field_names(): void
    {
        $captured = null;
        $resource = $this->makeResource($this->partialCreateResponse(), $captured);

        $resource->create([
            'name' => 'Daily Report',
            'queueName' => 'reports',
            'cronExpression' => '0 9 * * *',
            'payloadTemplate' => ['type' => 'daily'],
            'timezone' => 'UTC',
        ]);

        $this->assertIsArray($captured);
        $body = Casing::keysToSnakeCase($captured);

        $this->assertSame('reports', $body['queue_name']);
        $this->assertSame('0 9 * * *', $body['cron_expression']);
        $this->assertSame(['type' => 'daily'], $body['payload_template']);
    }

    #[Test]
    public function create_response_populates_timezone_from_request(): void
    {
        $captured = null;
        $resource = $this->makeResource($this->partialCreateResponse(), $captured);

        $schedule = $resource->create([
            'name' => 'Daily Report',
            'queue' => 'reports',
            'schedule' => '0 9 * * *',
            'payload' => ['type' => 'daily'],
            'timezone' => 'America/New_York',
        ]);

        // The create response omits timezone; create() backfills it from the
        // request so it matches what a follow-up get() would report.
        $this->assertSame('America/New_York', $schedule->timezone);
    }

    #[Test]
    public function create_response_defaults_timezone_to_utc_when_not_supplied(): void
    {
        $captured = null;
        $resource = $this->makeResource($this->partialCreateResponse(), $captured);

        $schedule = $resource->create([
            'name' => 'Daily Report',
            'queue' => 'reports',
            'schedule' => '0 9 * * *',
            'payload' => ['type' => 'daily'],
        ]);

        // Backend defaults timezone to 'UTC'; create() mirrors that default.
        $this->assertSame('UTC', $schedule->timezone);
    }

    #[Test]
    public function create_response_backfills_queue_and_payload(): void
    {
        $captured = null;
        $resource = $this->makeResource($this->partialCreateResponse(), $captured);

        $schedule = $resource->create([
            'name' => 'Daily Report',
            'queue' => 'reports',
            'schedule' => '0 9 * * *',
            'payload' => ['type' => 'daily'],
        ]);

        // queue_name and payload_template are also absent from the create
        // response; they are backfilled from the request too.
        $this->assertSame('reports', $schedule->queue);
        $this->assertSame(['type' => 'daily'], $schedule->payload);
        $this->assertSame('0 9 * * *', $schedule->schedule);
    }
}
