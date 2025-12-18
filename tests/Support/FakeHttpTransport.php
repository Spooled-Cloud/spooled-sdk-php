<?php

declare(strict_types=1);

namespace Spooled\Tests\Support;

use RuntimeException;

/**
 * Fake HTTP transport for testing.
 *
 * Records requests and returns stubbed responses.
 */
final class FakeHttpTransport
{
    /** @var array<array{method: string, path: string, body: mixed, query: mixed, headers: array}> */
    private array $requests = [];

    /** @var array<array{statusCode: int, body: string, headers: array}> */
    private array $responses = [];

    private int $responseIndex = 0;

    /**
     * Add a response to return for the next request.
     *
     * @param array<string, string> $headers
     */
    public function addResponse(int $statusCode, string $body, array $headers = []): self
    {
        $this->responses[] = [
            'statusCode' => $statusCode,
            'body' => $body,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * Add a JSON response.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function addJsonResponse(int $statusCode, array $data, array $headers = []): self
    {
        return $this->addResponse($statusCode, json_encode($data), $headers);
    }

    /**
     * Add a success response with JSON data.
     *
     * @param array<string, mixed> $data
     */
    public function addSuccessResponse(array $data): self
    {
        return $this->addJsonResponse(200, $data);
    }

    /**
     * Add an error response.
     */
    public function addErrorResponse(int $statusCode, string $message, ?string $code = null): self
    {
        return $this->addJsonResponse($statusCode, array_filter([
            'message' => $message,
            'code' => $code,
        ]));
    }

    /**
     * Record a request and return the next response.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, headers: array}
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
    ): array {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'query' => $query,
            'headers' => $headers,
        ];

        if ($this->responseIndex >= count($this->responses)) {
            return [
                'statusCode' => 500,
                'body' => '{"message": "No response configured"}',
                'headers' => [],
            ];
        }

        return $this->responses[$this->responseIndex++];
    }

    /**
     * Get all recorded requests.
     *
     * @return array<array{method: string, path: string, body: mixed, query: mixed, headers: array}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * Get the last recorded request.
     *
     * @return array{method: string, path: string, body: mixed, query: mixed, headers: array}|null
     */
    public function getLastRequest(): ?array
    {
        if (empty($this->requests)) {
            return null;
        }

        return $this->requests[count($this->requests) - 1];
    }

    /**
     * Get request count.
     */
    public function getRequestCount(): int
    {
        return count($this->requests);
    }

    /**
     * Check if a request was made to a specific path.
     */
    public function hasRequest(string $method, string $path): bool
    {
        foreach ($this->requests as $request) {
            if ($request['method'] === $method && str_contains($request['path'], $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset all requests and responses.
     */
    public function reset(): void
    {
        $this->requests = [];
        $this->responses = [];
        $this->responseIndex = 0;
    }

    /**
     * Assert a request was made.
     */
    public function assertRequestMade(string $method, string $path): void
    {
        if (!$this->hasRequest($method, $path)) {
            throw new RuntimeException("Expected {$method} request to {$path} was not made");
        }
    }

    /**
     * Assert request count.
     */
    public function assertRequestCount(int $expected): void
    {
        $actual = $this->getRequestCount();
        if ($actual !== $expected) {
            throw new RuntimeException("Expected {$expected} requests, got {$actual}");
        }
    }
}
