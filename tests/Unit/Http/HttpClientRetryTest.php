<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Config\CircuitBreakerConfig;
use Spooled\Config\RetryConfig;
use Spooled\Errors\CircuitBreakerOpenError;
use Spooled\Errors\NetworkError;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\TimeoutError;
use Spooled\Http\HttpClient;
use Spooled\Resources\JobsResource;
use Spooled\Tests\Support\HttpClientFactory;
use Spooled\Util\CircuitBreaker;
use Spooled\Util\RetryHandler;

#[CoversClass(HttpClient::class)]
#[CoversClass(RetryHandler::class)]
#[CoversClass(CircuitBreaker::class)]
#[CoversClass(RateLimitError::class)]
final class HttpClientRetryTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function connectRefused(): ConnectException
    {
        return new ConnectException('cURL error 7: Connection refused', new Request('GET', 'http://api.test/x'));
    }

    private function timedOut(): ConnectException
    {
        return new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'http://api.test/x'));
    }

    private function json(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], $body);
    }

    private function quotaBody(): string
    {
        return (string) json_encode([
            'error' => 'limit_exceeded',
            'code' => 'QUOTA_EXCEEDED',
            'message' => 'Active job limit reached',
            'resource' => 'active_jobs',
            'current' => 10,
            'limit' => 10,
            'plan' => 'free',
        ]);
    }

    // --- SDKGP-23: network errors and timeouts are retried for idempotent requests ---

    #[Test]
    public function a_get_is_retried_after_connection_errors(): void
    {
        $http = HttpClientFactory::create(
            [$this->connectRefused(), $this->connectRefused(), $this->json(200, '{"id":"job_1"}')],
            $this->history,
        );

        $result = $http->get('jobs/job_1');

        $this->assertSame('job_1', $result['id']);
        $this->assertCount(3, $this->history);
    }

    #[Test]
    public function a_get_is_retried_after_a_timeout(): void
    {
        $http = HttpClientFactory::create([$this->timedOut(), $this->json(200, '{"ok":true}')], $this->history);

        $this->assertTrue($http->get('health')['ok']);
        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function retries_are_bounded_and_surface_the_network_error(): void
    {
        $http = HttpClientFactory::create(
            array_fill(0, 6, $this->connectRefused()),
            $this->history,
            new RetryConfig(maxRetries: 2, baseDelay: 0.001, maxDelay: 0.01, jitter: 0.0),
        );

        try {
            $http->get('jobs');
            $this->fail('expected a NetworkError');
        } catch (NetworkError $e) {
            $this->assertCount(3, $this->history); // first attempt + 2 retries
        }
    }

    #[Test]
    public function a_plain_post_is_not_retried_after_a_network_error(): void
    {
        $http = HttpClientFactory::create(
            [$this->connectRefused(), $this->json(200, '{"id":"job_1","created":true}')],
            $this->history,
        );

        try {
            (new JobsResource($http))->create(['queue' => 'emails', 'payload' => ['n' => 1]]);
            $this->fail('expected a NetworkError');
        } catch (NetworkError) {
            $this->assertCount(1, $this->history);
        }
    }

    #[Test]
    public function a_post_timeout_is_not_retried_either(): void
    {
        $http = HttpClientFactory::create([$this->timedOut(), $this->json(200, '{}')], $this->history);

        $this->expectException(TimeoutError::class);
        $http->post('jobs', ['queueName' => 'q']);
    }

    #[Test]
    public function create_with_an_idempotency_key_is_retried(): void
    {
        $http = HttpClientFactory::create(
            [$this->connectRefused(), $this->json(200, '{"id":"job_1","created":true}')],
            $this->history,
        );

        $got = (new JobsResource($http))->create([
            'queue' => 'emails',
            'payload' => ['n' => 1],
            'idempotencyKey' => 'order-42',
        ]);

        $this->assertSame('job_1', $got->id);
        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function acks_and_heartbeats_are_retried_on_network_errors_timeouts_and_5xx(): void
    {
        $http = HttpClientFactory::create(
            [
                $this->connectRefused(),
                $this->json(503, '{"message":"unavailable"}'),
                $this->json(200, '{"success":true}'),
                // fail(): timeout then ok
                $this->timedOut(),
                $this->json(200, '{"success":true}'),
                // heartbeat: 502 then ok
                $this->json(502, '{"message":"bad gateway"}'),
                $this->json(200, '{"success":true}'),
            ],
            $this->history,
        );
        $jobs = new JobsResource($http);

        $this->assertTrue($jobs->complete('job_1', ['workerId' => 'w1', 'leaseId' => 'l1'])['success']);
        $this->assertTrue($jobs->fail('job_1', ['workerId' => 'w1', 'error' => 'boom'])['success']);
        $this->assertTrue($jobs->heartbeat('job_1', ['workerId' => 'w1'])['success']);
        $this->assertCount(7, $this->history);
    }

    #[Test]
    public function a_rejected_ack_is_not_retried(): void
    {
        $http = HttpClientFactory::create(
            [$this->json(409, '{"code":"LEASE_EXPIRED","message":"Job lease expired before completion"}')],
            $this->history,
        );

        try {
            (new JobsResource($http))->complete('job_1', ['workerId' => 'w1']);
            $this->fail('expected a ConflictError');
        } catch (\Spooled\Errors\ConflictError) {
            $this->assertCount(1, $this->history);
        }
    }

    // --- Quota 429s ---

    #[Test]
    public function a_quota_429_is_recognised_with_or_without_a_code(): void
    {
        $withCode = new RateLimitError('x', 'QUOTA_EXCEEDED');
        $legacyBody = new RateLimitError('x', null, [], null, '{"error":"limit_exceeded","resource":"queues","limit":5,"plan":"free"}');
        $rateLimited = new RateLimitError('slow down', 'RATE_LIMITED', [], null, '{"message":"slow down"}', retryAfter: 2.0);
        $legacyWithRetryAfter = new RateLimitError('x', null, [], null, '{"error":"limit_exceeded"}', retryAfter: 2.0);

        $this->assertTrue($withCode->isQuotaExceeded());
        $this->assertTrue($legacyBody->isQuotaExceeded());
        $this->assertFalse($rateLimited->isQuotaExceeded());
        $this->assertFalse($legacyWithRetryAfter->isQuotaExceeded());
    }

    #[Test]
    public function a_quota_429_is_not_retried_even_for_a_get(): void
    {
        $http = HttpClientFactory::create(
            [$this->json(429, $this->quotaBody()), $this->json(200, '{}')],
            $this->history,
        );

        try {
            $http->get('queues');
            $this->fail('expected a RateLimitError');
        } catch (RateLimitError $e) {
            $this->assertSame('QUOTA_EXCEEDED', $e->errorCode);
            $this->assertCount(1, $this->history);
        }
    }

    #[Test]
    public function a_real_rate_limit_is_still_retried_after_retry_after(): void
    {
        $http = HttpClientFactory::create(
            [
                $this->json(429, '{"code":"RATE_LIMITED","message":"slow down"}', ['Retry-After' => '0']),
                $this->json(200, '{"ok":true}'),
            ],
            $this->history,
        );

        $this->assertTrue($http->get('jobs')['ok']);
        $this->assertCount(2, $this->history);
    }

    #[Test]
    public function enqueue_quota_errors_never_open_the_breaker_so_acks_still_go_through(): void
    {
        $queue = [];
        for ($i = 0; $i < 8; $i++) {
            $queue[] = $this->json(429, $this->quotaBody());
        }
        $queue[] = $this->json(200, '{"success":true}');

        $http = HttpClientFactory::create(
            $queue,
            $this->history,
            circuitBreaker: new CircuitBreakerConfig(failureThreshold: 3),
        );
        $jobs = new JobsResource($http);

        for ($i = 0; $i < 8; $i++) {
            try {
                $jobs->create(['queue' => 'emails', 'payload' => []]);
                $this->fail('expected a quota error');
            } catch (RateLimitError $e) {
                $this->assertTrue($e->isQuotaExceeded());
            }
        }

        $this->assertSame('closed', $http->getCircuitBreaker()->getState());
        $this->assertTrue($jobs->complete('job_1', ['workerId' => 'w1'])['success']);
    }

    #[Test]
    public function a_genuine_429_still_counts_against_the_breaker(): void
    {
        $queue = array_fill(0, 3, $this->json(429, '{"message":"slow down"}', ['Retry-After' => '1']));
        $http = HttpClientFactory::create(
            $queue,
            $this->history,
            new RetryConfig(maxRetries: 0),
            new CircuitBreakerConfig(failureThreshold: 3),
        );

        for ($i = 0; $i < 3; $i++) {
            try {
                $http->post('jobs', ['queueName' => 'q']);
            } catch (RateLimitError) {
            }
        }

        $this->expectException(CircuitBreakerOpenError::class);
        $http->post('jobs', ['queueName' => 'q']);
    }

    // --- Request encoding ---

    #[Test]
    public function an_unencodable_body_fails_fast_and_is_not_retried(): void
    {
        $http = HttpClientFactory::create([$this->json(200, '{}'), $this->json(200, '{}')], $this->history);

        try {
            $http->put('jobs/job_1/priority', ['payload' => "bad \xB1 utf8"]);
            $this->fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('JSON-encoded', $e->getMessage());
            $this->assertCount(0, $this->history);
        }
    }

    #[Test]
    public function an_empty_body_is_an_object_and_floats_keep_their_fraction(): void
    {
        $http = HttpClientFactory::create([$this->json(200, '{}'), $this->json(200, '{}')], $this->history);

        $http->post('workers/w1/heartbeat', []);
        $http->post('jobs', ['queueName' => 'q', 'payload' => ['price' => 10.0]]);

        $this->assertSame('{}', (string) $this->history[0]['request']->getBody());
        $this->assertStringContainsString('"price":10.0', (string) $this->history[1]['request']->getBody());
    }
}
