<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Config\RetryConfig;
use Spooled\Errors\NetworkError;
use Spooled\Errors\RateLimitError;
use Spooled\Http\HttpClient;
use Spooled\Tests\Support\HttpClientFactory;
use Spooled\Types\RateLimitInfo;

/**
 * The API sends X-RateLimit-* on every response, not only on a 429; the
 * client used to read them on a 429 and drop them on every success.
 */
#[CoversClass(HttpClient::class)]
#[CoversClass(RateLimitInfo::class)]
#[CoversClass(RateLimitError::class)]
final class ResponseMetadataTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param array<string, string> $headers
     */
    private function json(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], $body);
    }

    /** @return array<string, string> */
    private function rateLimitHeaders(int $remaining): array
    {
        return [
            'x-ratelimit-limit' => '200',
            'x-ratelimit-remaining' => (string) $remaining,
            'x-ratelimit-reset' => '1791788718',
            'x-ratelimit-scope' => 'organization',
            'X-Has-More' => 'false',
        ];
    }

    #[Test]
    public function rate_limit_headers_of_a_successful_response_are_exposed(): void
    {
        $client = HttpClientFactory::create([$this->json(200, '[]', $this->rateLimitHeaders(199))], $this->history);

        $this->assertNull($client->getLastRateLimit());
        $client->get('jobs');

        $info = $client->getLastRateLimit();
        $this->assertNotNull($info);
        $this->assertSame(200, $info->limit);
        $this->assertSame(199, $info->remaining);
        $this->assertSame(1791788718, $info->reset);
        $this->assertSame('organization', $info->scope);
        $this->assertSame(18, $info->secondsUntilReset(1791788700));
        $this->assertSame('false', $client->getLastResponseHeader('x-has-more'));
        $this->assertSame('false', $client->getLastResponseHeader('X-Has-More'));
    }

    #[Test]
    public function a_response_without_rate_limit_headers_keeps_the_last_known_budget(): void
    {
        $client = HttpClientFactory::create([
            $this->json(200, '[]', $this->rateLimitHeaders(150)),
            $this->json(200, '{"status":"healthy"}'),
        ], $this->history);

        $client->get('jobs');
        $client->get('health', skipApiPrefix: true);

        $this->assertSame(150, $client->getLastRateLimit()?->remaining);
        $this->assertNull($client->getLastResponseHeader('x-has-more'), 'headers are per response');
    }

    #[Test]
    public function a_request_without_a_response_leaves_no_stale_headers(): void
    {
        $client = HttpClientFactory::create(
            [
                $this->json(200, '[]', $this->rateLimitHeaders(10)),
                new ConnectException('cURL error 7: Connection refused', new Request('GET', 'http://api.test/x')),
            ],
            $this->history,
            new RetryConfig(maxRetries: 0),
        );

        $client->get('jobs');

        try {
            $client->get('jobs');
            $this->fail('expected a NetworkError');
        } catch (NetworkError) {
        }

        $this->assertSame([], $client->getLastResponseHeaders());
        $this->assertSame(10, $client->getLastRateLimit()?->remaining);
    }

    #[Test]
    public function a_429_records_the_budget_and_carries_the_scope(): void
    {
        $client = HttpClientFactory::create(
            [$this->json(429, '{"code":"RATE_LIMIT_EXCEEDED","message":"slow down"}', $this->rateLimitHeaders(0) + ['Retry-After' => '59'])],
            $this->history,
            new RetryConfig(maxRetries: 0),
        );

        try {
            $client->get('jobs');
            $this->fail('expected a RateLimitError');
        } catch (RateLimitError $e) {
            $this->assertSame('organization', $e->scope);
            $this->assertSame(0, $e->remaining);
            $this->assertSame(1791788718, $e->getRateLimitInfo()->reset);
            $this->assertSame(59.0, $e->getRateLimitInfo()->retryAfter);
        }

        $this->assertSame(0, $client->getLastRateLimit()?->remaining);
        $this->assertSame(59.0, $client->getLastRateLimit()?->retryAfter);
    }

    #[Test]
    public function from_headers_is_null_without_rate_limit_headers_and_ignores_garbage(): void
    {
        $this->assertNull(RateLimitInfo::fromHeaders(['Content-Type' => 'application/json']));

        $info = RateLimitInfo::fromHeaders(['X-RateLimit-Limit' => ['60'], 'X-RateLimit-Remaining' => 'n/a']);
        $this->assertNotNull($info);
        $this->assertSame(60, $info->limit);
        $this->assertNull($info->remaining);
        $this->assertNull($info->reset);
        $this->assertNull($info->secondsUntilReset());
    }
}
