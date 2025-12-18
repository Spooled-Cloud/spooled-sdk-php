<?php

declare(strict_types=1);

namespace Spooled\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spooled\Config\ClientOptions;
use Spooled\Errors\NetworkError;
use Spooled\Errors\SpooledError;
use Spooled\Errors\TimeoutError;
use Spooled\Util\Casing;
use Spooled\Util\CircuitBreaker;
use Spooled\Util\RetryHandler;
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
     * @param array<string, string> $headers
     */
    public function getRaw(
        string $path,
        array $headers = [],
        bool $skipApiPrefix = false,
    ): string {
        $url = $this->buildUrl($path, $skipApiPrefix);
        $allHeaders = $this->buildHeaders($headers);
        $query = $this->addApiKeyToQuery([]);

        return $this->circuitBreaker->execute(function () use ($url, $allHeaders, $query): string {
            return $this->retryHandler->execute(function () use ($url, $allHeaders, $query): string {
                try {
                    $options = [
                        RequestOptions::HEADERS => $allHeaders,
                    ];

                    if ($query !== []) {
                        $options[RequestOptions::QUERY] = $query;
                    }

                    $response = $this->guzzle->request('GET', $url, $options);

                    $statusCode = $response->getStatusCode();
                    $body = (string) $response->getBody();

                    if ($statusCode >= 400) {
                        throw SpooledError::fromResponse(
                            $statusCode,
                            $body,
                            $this->flattenHeaders($response->getHeaders()),
                        );
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

        // Convert request body keys to snake_case
        if ($body !== null) {
            $body = Casing::keysToSnakeCase($body);
        }

        // Convert query keys to snake_case
        if ($query !== []) {
            $query = Casing::keysToSnakeCase($query);
        }

        return $this->circuitBreaker->execute(function () use ($method, $url, $body, $query, $allHeaders, $forceRetry): array {
            return $this->retryHandler->execute(function () use ($method, $url, $body, $query, $allHeaders): array {
                return $this->executeRequest($method, $url, $body, $query, $allHeaders);
            }, $method, $forceRetry);
        });
    }

    /**
     * Execute a single HTTP request.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function executeRequest(
        string $method,
        string $url,
        ?array $body,
        array $query,
        array $headers,
    ): array {
        $options = [
            RequestOptions::HEADERS => $headers,
        ];

        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }

        try {
            $response = $this->guzzle->request($method, $url, $options);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            $responseHeaders = $this->flattenHeaders($response->getHeaders());

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
                throw SpooledError::fromResponse(
                    $response->getStatusCode(),
                    (string) $response->getBody(),
                    $this->flattenHeaders($response->getHeaders()),
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

                $response = $this->guzzle->request('POST', $url, $options);

                $statusCode = $response->getStatusCode();
                $responseBody = (string) $response->getBody();
                $responseHeaders = $this->flattenHeaders($response->getHeaders());

                if ($statusCode >= 400) {
                    throw SpooledError::fromResponse($statusCode, $responseBody, $responseHeaders);
                }
            }, 'POST', true);
        });
    }

    /**
     * Add API key to query parameters if using API key auth.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function addApiKeyToQuery(array $query): array
    {
        // Add api_key query parameter if not using JWT
        if (
            ($this->accessToken === null || $this->accessToken === '')
            && $this->options->apiKey !== null
            && $this->options->apiKey !== ''
        ) {
            $query['api_key'] = $this->options->apiKey;
        }

        return $query;
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
