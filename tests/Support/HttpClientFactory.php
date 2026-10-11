<?php

declare(strict_types=1);

namespace Spooled\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use Psr\Log\NullLogger;
use ReflectionClass;
use Spooled\Config\CircuitBreakerConfig;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;
use Spooled\Http\HttpClient;
use Spooled\Util\CircuitBreaker;
use Spooled\Util\RetryHandler;

/**
 * Builds a real HttpClient (real retry handler, real circuit breaker, real
 * request building) on top of a Guzzle MockHandler, so tests exercise the whole
 * client without touching the network.
 */
final class HttpClientFactory
{
    /**
     * @param array<mixed> $queue Responses and exceptions for Guzzle's MockHandler
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $history Filled with one entry per request actually sent
     */
    public static function create(
        array $queue,
        array &$history,
        ?RetryConfig $retry = null,
        ?CircuitBreakerConfig $circuitBreaker = null,
        string $apiKey = 'sp_test_key',
    ): HttpClient {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        $options = new ClientOptions(
            apiKey: $apiKey,
            baseUrl: 'http://api.test',
            retry: $retry ?? new RetryConfig(maxRetries: 3, baseDelay: 0.001, maxDelay: 0.01, jitter: 0.0),
            circuitBreaker: $circuitBreaker ?? new CircuitBreakerConfig(),
        );

        $reflection = new ReflectionClass(HttpClient::class);
        $client = $reflection->newInstanceWithoutConstructor();
        $values = [
            'options' => $options,
            'logger' => new NullLogger(),
            'accessToken' => null,
            'refreshToken' => null,
            'guzzle' => new GuzzleClient([
                'handler' => $stack,
                'base_uri' => $options->baseUrl,
                RequestOptions::HTTP_ERRORS => false,
            ]),
            'circuitBreaker' => new CircuitBreaker($options->circuitBreaker),
            'retryHandler' => new RetryHandler($options->retry, new NullLogger()),
        ];
        foreach ($values as $name => $value) {
            $reflection->getProperty($name)->setValue($client, $value);
        }

        return $client;
    }
}
