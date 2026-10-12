<?php

declare(strict_types=1);

namespace Spooled\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spooled\Config\ClientOptions;
use Spooled\Errors\NetworkError;
use Spooled\Errors\SpooledError;
use Spooled\Errors\TimeoutError;
use Spooled\Types\RateLimitInfo;
use Spooled\Util\Casing;
use Spooled\Util\CircuitBreaker;
use Spooled\Util\RetryHandler;
use stdClass;
use Throwable;

/**
 * HTTP client for making API requests.
 */
class HttpClient
{
    private readonly GuzzleClient $guzzle;

    private readonly CircuitBreaker $circuitBreaker;

    private readonly RetryHandler $retryHandler;

    private readonly LoggerInterface $logger;

    private ?string $accessToken;

    private ?string $refreshToken;

    /**
     * Headers of the most recent HTTP response (lower-cased name => first
     * value). Empty while a request is in flight or when it got no response.
     *
     * @var array<string, string>
     */
    private array $lastResponseHeaders = [];

    /** Rate-limit headers of the most recent response that carried them. */
    private ?RateLimitInfo $lastRateLimit = null;

    public function __construct(
        private readonly ClientOptions $options,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->accessToken = $options->accessToken;
        $this->refreshToken = $options->refreshToken;

        $this->guzzle = new GuzzleClient([
            'base_uri' => $options->baseUrl,
            RequestOptions::CONNECT_TIMEOUT => $options->connectTimeout,
            RequestOptions::TIMEOUT => $options->requestTimeout,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => $options->userAgent,
            ],
        ]);

        $this->circuitBreaker = new CircuitBreaker($options->circuitBreaker);
        $this->retryHandler = new RetryHandler($options->retry, $this->logger);
    }

    /**
     * Make a GET request.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function get(
        string $path,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
    ): array {
        return $this->request('GET', $path, query: $query, headers: $headers, skipApiPrefix: $skipApiPrefix);
    }

    /**
     * Make a POST request.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function post(
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
        bool $forceRetry = false,
    ): array {
        return $this->request('POST', $path, $body, $query, $headers, $skipApiPrefix, $forceRetry);
    }

    /**
     * Make a PUT request.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function put(
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
    ): array {
        return $this->request('PUT', $path, $body, $query, $headers, $skipApiPrefix);
    }

    /**
     * Make a PATCH request.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function patch(
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
    ): array {
        return $this->request('PATCH', $path, $body, $query, $headers, $skipApiPrefix);
    }

    /**
     * Make a DELETE request.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function delete(
        string $path,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
    ): array {
        return $this->request('DELETE', $path, query: $query, headers: $headers, skipApiPrefix: $skipApiPrefix);
    }

    /**
     * Make a raw request and return the response body as string.
     *
     * Credentials travel in the `Authorization` header only. The API rejects
     * credentials passed as query parameters, and a query string is recorded by
     * proxy and CDN access logs, tracing spans, browser history and the
     * `Referer` header, so no credential is ever appended to the URL.
     *
     * @param array<string, string> $headers
     */
    public function getRaw(
        string $path,
        array $headers = [],
        bool $skipApiPrefix = false,
    ): string {
        $url = $this->buildUrl($path, $skipApiPrefix);
        $allHeaders = $this->buildHeaders($headers);

        return $this->circuitBreaker->execute(function () use ($url, $allHeaders): string {
            return $this->retryHandler->execute(function () use ($url, $allHeaders): string {
                try {
                    $options = [
                        RequestOptions::HEADERS => $allHeaders,
                    ];

                    $this->lastResponseHeaders = [];
                    $response = $this->guzzle->request('GET', $url, $options);

                    $statusCode = $response->getStatusCode();
                    $body = (string) $response->getBody();
                    $responseHeaders = $this->flattenHeaders($response->getHeaders());
                    $this->recordResponse($responseHeaders);

                    if ($statusCode >= 400) {
                        throw SpooledError::fromResponse($statusCode, $body, $responseHeaders);
                    }

                    return $body;
                } catch (ConnectException $e) {
                    if (str_contains($e->getMessage(), 'timed out')) {
                        throw new TimeoutError(
                            'Request timed out: ' . $e->getMessage(),
                            $this->options->requestTimeout,
                            $e,
                        );
                    }

                    throw new NetworkError('Connection failed: ' . $e->getMessage(), $e);
                } catch (RequestException $e) {
                    if (str_contains($e->getMessage(), 'timed out')) {
                        throw new TimeoutError(
                            'Request timed out: ' . $e->getMessage(),
                            $this->options->requestTimeout,
                            $e,
                        );
                    }

                    throw new NetworkError('Request failed: ' . $e->getMessage(), $e);
                }
            }, 'GET');
        });
    }

    /**
     * Get the current access token.
     */
    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Update the access token.
     */
    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }

    /**
     * Update the refresh token.
     */
    public function setRefreshToken(string $token): void
    {
        $this->refreshToken = $token;
    }

    /**
     * Get the current refresh token.
     */
    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * Headers of the most recent HTTP response, keyed by lower-cased name
     * (first value of each). Empty when the last request got no response.
     *
     * @return array<string, string>
     */
    public function getLastResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }

    /**
     * One header of the most recent HTTP response (case-insensitive name).
     */
    public function getLastResponseHeader(string $name): ?string
    {
        return $this->lastResponseHeaders[strtolower($name)] ?? null;
    }

    /**
     * The `X-RateLimit-*` values of the most recent response that carried
     * them (success or error), or null before any such response.
     */
    public function getLastRateLimit(): ?RateLimitInfo
    {
        return $this->lastRateLimit;
    }

    /**
     * Get the circuit breaker instance.
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * Make an HTTP request.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
        bool $forceRetry = false,
    ): array {
        $url = $this->buildUrl($path, $skipApiPrefix);
        $allHeaders = $this->buildHeaders($headers);

        // Convert request body keys to snake_case and encode once, up front. An
        // unencodable body (invalid UTF-8, NaN, recursion) is a caller error;
        // letting Guzzle encode it inside the retried callable surfaced it as a
        // NetworkError("Unexpected error") that looked like a transport failure.
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = $this->encodeBody(Casing::keysToSnakeCase($body));
        }

        // Convert query keys to snake_case
        if ($query !== []) {
            $query = Casing::keysToSnakeCase($query);
        }

        return $this->circuitBreaker->execute(function () use ($method, $url, $encodedBody, $query, $allHeaders, $forceRetry): array {
            return $this->retryHandler->execute(function () use ($method, $url, $encodedBody, $query, $allHeaders): array {
                return $this->executeRequest($method, $url, $encodedBody, $query, $allHeaders);
            }, $method, $forceRetry);
        });
    }

    /**
     * Execute a single HTTP request.
     *
     * @param string|null $body JSON-encoded request body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function executeRequest(
        string $method,
        string $url,
        ?string $body,
        array $query,
        array $headers,
    ): array {
        $options = [
            RequestOptions::HEADERS => $headers,
        ];

        if ($body !== null) {
            $options[RequestOptions::BODY] = $body;
        }

        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }

        // Never let a failed attempt leave the previous response's headers behind.
        $this->lastResponseHeaders = [];

        try {
            $response = $this->guzzle->request($method, $url, $options);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            $responseHeaders = $this->flattenHeaders($response->getHeaders());
            $this->recordResponse($responseHeaders);

            // Handle error responses
            if ($statusCode >= 400) {
                throw SpooledError::fromResponse($statusCode, $responseBody, $responseHeaders);
            }

            // Parse response
            return $this->parseResponse($responseBody);
        } catch (ConnectException $e) {
            if (str_contains($e->getMessage(), 'timed out')) {
                throw new TimeoutError(
                    'Request timed out: ' . $e->getMessage(),
                    $this->options->requestTimeout,
                    $e,
                );
            }

            throw new NetworkError('Connection failed: ' . $e->getMessage(), $e);
        } catch (RequestException $e) {
            if (str_contains($e->getMessage(), 'timed out')) {
                throw new TimeoutError(
                    'Request timed out: ' . $e->getMessage(),
                    $this->options->requestTimeout,
                    $e,
                );
            }

            if ($e->hasResponse() && ($response = $e->getResponse()) !== null) {
                $responseHeaders = $this->flattenHeaders($response->getHeaders());
                $this->recordResponse($responseHeaders);

                throw SpooledError::fromResponse(
                    $response->getStatusCode(),
                    (string) $response->getBody(),
                    $responseHeaders,
                );
            }

            throw new NetworkError('Request failed: ' . $e->getMessage(), $e);
        } catch (SpooledError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new NetworkError('Unexpected error: ' . $e->getMessage(), $e);
        }
    }

    /**
     * JSON-encode a request body.
     *
     * A top-level empty array is an empty JSON object (every endpoint takes an
     * object; `[]` was rejected), and floats keep their fraction (`1.0` stays
     * `1.0`, not `1`).
     *
     * @param array<string, mixed> $body
     * @throws InvalidArgumentException when the body cannot be encoded
     */
    private function encodeBody(array $body): string
    {
        try {
            return json_encode(
                $body === [] ? new stdClass() : $body,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Request body cannot be JSON-encoded: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the full URL for a request.
     */
    private function buildUrl(string $path, bool $skipApiPrefix): string
    {
        // Remove leading slash for consistency
        $path = ltrim($path, '/');

        // Add API prefix if not skipped
        if (!$skipApiPrefix && !str_starts_with($path, 'api/')) {
            $path = 'api/v1/' . $path;
        }

        return $path;
    }

    /**
     * Build headers for a request.
     *
     * @param array<string, string> $additionalHeaders
     * @return array<string, string>
     */
    private function buildHeaders(array $additionalHeaders = []): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => $this->options->userAgent,
        ];

        // Add custom headers from options
        foreach ($this->options->headers as $name => $value) {
            $headers[$name] = $value;
        }

        // Add auth header - Node/Python semantics:
        // - if accessToken is present, use it as Bearer
        // - else if apiKey is present, use it as Bearer
        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        } elseif ($this->options->apiKey !== null && $this->options->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->options->apiKey;
        }

        // Add additional headers (can override defaults)
        foreach ($additionalHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Make a raw POST request (sends body bytes as-is, skips casing conversion and JSON encoding).
     *
     * Used for webhook ingestion endpoints where signature verification depends on raw bytes.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function postRaw(
        string $path,
        string $rawBody,
        array $query = [],
        array $headers = [],
        bool $skipApiPrefix = false,
    ): void {
        $url = $this->buildUrl($path, $skipApiPrefix);
        $allHeaders = $this->buildHeaders($headers);

        // Query keys should still be snake_case if present
        if ($query !== []) {
            $query = Casing::keysToSnakeCase($query);
        }

        $this->circuitBreaker->execute(function () use ($url, $rawBody, $query, $allHeaders): void {
            $this->retryHandler->execute(function () use ($url, $rawBody, $query, $allHeaders): void {
                $options = [
                    RequestOptions::HEADERS => $allHeaders,
                    RequestOptions::BODY => $rawBody,
                ];

                if ($query !== []) {
                    $options[RequestOptions::QUERY] = $query;
                }

                $this->lastResponseHeaders = [];
                $response = $this->guzzle->request('POST', $url, $options);

                $statusCode = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
                $responseHeaders = $this->flattenHeaders($response->getHeaders());
                $this->recordResponse($responseHeaders);

                if ($statusCode >= 400) {
                    throw SpooledError::fromResponse($statusCode, $responseBody, $responseHeaders);
                }
            }, 'POST', true);
        });
    }

    /**
     * Parse response body as JSON and convert keys to camelCase.
     *
     * @return array<string, mixed>
     */
    private function parseResponse(string $body): array
    {
        if ($body === '' || $body === '{}') {
            return [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return [];
            }

            // Convert response keys to camelCase
            return Casing::keysToCamelCase($data);
        } catch (JsonException) {
            // Return raw body wrapped in a data key if not valid JSON
            return ['data' => $body];
        }
    }

    /**
     * Remember a response's headers and, when it carries them, its
     * `X-RateLimit-*` values.
     *
     * @param array<string, string> $headers
     */
    private function recordResponse(array $headers): void
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = $value;
        }
        $this->lastResponseHeaders = $lower;

        $rateLimit = RateLimitInfo::fromHeaders($lower);
        if ($rateLimit !== null) {
            $this->lastRateLimit = $rateLimit;
        }
    }

    /**
     * Flatten headers from Guzzle format to simple key-value.
     *
     * @param array<string, string[]> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $values) {
            $result[$name] = $values[0] ?? '';
        }

        return $result;
    }
}
