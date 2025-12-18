<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Exception;
use JsonException;
use Throwable;

/**
 * Base exception for all Spooled SDK errors.
 */
class SpooledError extends Exception
{
    /** HTTP status code */
    public readonly int $statusCode;

    /** Error code from API */
    public readonly ?string $errorCode;

    /** Error details */
    public readonly array $details;

    /** Request ID for debugging */
    public readonly ?string $requestId;

    /** Raw response body */
    public readonly ?string $rawBody;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->requestId = $requestId;
        $this->rawBody = $rawBody;
    }

    /**
     * Create an error from an HTTP response.
     *
     * @param int $statusCode
     * @param string $body
     * @param array<string, string|string[]> $headers
     */
    public static function fromResponse(int $statusCode, string $body, array $headers = []): self
    {
        $requestId = self::extractHeader($headers, 'X-Request-Id');
        $data = self::parseBody($body);

        $message = $data['message'] ?? $data['error'] ?? "HTTP error {$statusCode}";
        $code = $data['code'] ?? null;
        $details = $data['details'] ?? [];

        // Map status code to specific error type
        return match ($statusCode) {
            401 => new AuthenticationError($message, $code, $details, $requestId, $body),
            403 => new AuthorizationError($message, $code, $details, $requestId, $body),
            404 => new NotFoundError($message, $code, $details, $requestId, $body),
            409 => new ConflictError($message, $code, $details, $requestId, $body),
            413 => new PayloadTooLargeError($message, $code, $details, $requestId, $body),
            422 => new ValidationError($message, $code, $details, $requestId, $body),
            429 => self::createRateLimitError($message, $code, $details, $requestId, $body, $headers),
            default => $statusCode >= 500
                ? new ServerError($message, $statusCode, $code, $details, $requestId, $body)
                : new self($message, $statusCode, $code, $details, $requestId, $body),
        };
    }

    /**
     * Create a RateLimitError from response headers.
     *
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed> $details
     */
    private static function createRateLimitError(
        string $message,
        ?string $code,
        array $details,
        ?string $requestId,
        string $body,
        array $headers,
    ): RateLimitError {
        $retryAfter = self::parseRetryAfter($headers);
        $limit = self::extractHeader($headers, 'X-RateLimit-Limit');
        $remaining = self::extractHeader($headers, 'X-RateLimit-Remaining');
        $reset = self::extractHeader($headers, 'X-RateLimit-Reset');

        return new RateLimitError(
            message: $message,
            errorCode: $code,
            details: $details,
            requestId: $requestId,
            rawBody: $body,
            retryAfter: $retryAfter !== null ? (float) $retryAfter : null,
            limit: $limit !== null ? (int) $limit : null,
            remaining: $remaining !== null ? (int) $remaining : null,
            reset: $reset !== null ? (int) $reset : null,
        );
    }

    /**
     * Parse the response body as JSON.
     *
     * @return array<string, mixed>
     */
    private static function parseBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];
        } catch (JsonException) {
            return ['message' => $body];
        }
    }

    /**
     * Extract a header value.
     *
     * @param array<string, string|string[]> $headers
     */
    private static function extractHeader(array $headers, string $name): ?string
    {
        // Headers might be lowercase or original case
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    /**
     * Parse Retry-After header.
     *
     * @param array<string, string|string[]> $headers
     */
    private static function parseRetryAfter(array $headers): ?string
    {
        $value = self::extractHeader($headers, 'Retry-After');

        if ($value === null) {
            return null;
        }

        // Check if it's seconds or HTTP date
        if (is_numeric($value)) {
            return $value;
        }

        // Parse as HTTP date
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return (string) max(0, $timestamp - time());
        }

        return null;
    }

    /**
     * Get error as array for logging/debugging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'statusCode' => $this->statusCode,
            'errorCode' => $this->errorCode,
            'details' => $this->details,
            'requestId' => $this->requestId,
        ];
    }
}
