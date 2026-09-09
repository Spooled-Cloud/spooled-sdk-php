<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Http\HttpClient;
use Spooled\Resources\JobsResource;
use Spooled\Types\CreateJobResult;

#[CoversClass(JobsResource::class)]
#[CoversClass(CreateJobResult::class)]
final class JobsResourceTest extends TestCase
{
    #[Test]
    public function create_reads_id_and_created_not_a_full_job(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('post')->willReturn([
            'id' => 'job_1',
            'created' => false,
        ]);

        $got = (new JobsResource($httpClient))->create([
            'queue' => 'emails',
            'payload' => ['n' => 1],
        ]);

        $this->assertInstanceOf(CreateJobResult::class, $got);
        $this->assertSame('job_1', $got->id);
        $this->assertFalse($got->created);
    }
}
