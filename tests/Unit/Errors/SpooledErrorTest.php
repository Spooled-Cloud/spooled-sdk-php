<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Errors\AuthenticationError;
use Spooled\Errors\AuthorizationError;
use Spooled\Errors\ConflictError;
use Spooled\Errors\NotFoundError;
use Spooled\Errors\PayloadTooLargeError;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\ServerError;
use Spooled\Errors\SpooledError;
use Spooled\Errors\ValidationError;

#[CoversClass(SpooledError::class)]
final class SpooledErrorTest extends TestCase
{
    #[Test]
    public function it_creates_base_error_with_all_properties(): void
    {
        $error = new SpooledError(
            message: 'Test error',
            statusCode: 500,
            errorCode: 'TEST_ERROR',
            details: ['field' => 'value'],
            requestId: 'req-123',
            rawBody: '{"error": "test"}',
        );

        $this->assertSame('Test error', $error->getMessage());
        $this->assertSame(500, $error->statusCode);
        $this->assertSame('TEST_ERROR', $error->errorCode);
        $this->assertSame(['field' => 'value'], $error->details);
        $this->assertSame('req-123', $error->requestId);
        $this->assertSame('{"error": "test"}', $error->rawBody);
    }

    #[Test]
    #[DataProvider('statusCodeMappingProvider')]
    public function it_maps_status_code_to_correct_error_type(int $statusCode, string $expectedClass): void
    {
        $error = SpooledError::fromResponse($statusCode, '{"message": "test"}');

        $this->assertInstanceOf($expectedClass, $error);
        $this->assertSame($statusCode, $error->statusCode);
    }

    public static function statusCodeMappingProvider(): array
    {
        return [
            '401 -> AuthenticationError' => [401, AuthenticationError::class],
            '403 -> AuthorizationError' => [403, AuthorizationError::class],
            '404 -> NotFoundError' => [404, NotFoundError::class],
            '409 -> ConflictError' => [409, ConflictError::class],
            '413 -> PayloadTooLargeError' => [413, PayloadTooLargeError::class],
            '422 -> ValidationError' => [422, ValidationError::class],
            '429 -> RateLimitError' => [429, RateLimitError::class],
            '500 -> ServerError' => [500, ServerError::class],
            '502 -> ServerError' => [502, ServerError::class],
            '503 -> ServerError' => [503, ServerError::class],
            '400 -> SpooledError (generic)' => [400, SpooledError::class],
            '418 -> SpooledError (generic)' => [418, SpooledError::class],
        ];
    }

    #[Test]
    public function it_parses_json_response_body(): void
    {
        $body = json_encode([
            'message' => 'Resource not found',
            'code' => 'NOT_FOUND',
            'details' => ['resource' => 'job', 'id' => '123'],
        ]);

        $error = SpooledError::fromResponse(404, $body);

        $this->assertInstanceOf(NotFoundError::class, $error);
        $this->assertSame('Resource not found', $error->getMessage());
        $this->assertSame('NOT_FOUND', $error->errorCode);
        $this->assertSame(['resource' => 'job', 'id' => '123'], $error->details);
    }

    #[Test]
    public function it_handles_invalid_json_body(): void
    {
        $error = SpooledError::fromResponse(500, 'Invalid JSON response');

        $this->assertInstanceOf(ServerError::class, $error);
        $this->assertSame('Invalid JSON response', $error->getMessage());
    }

    #[Test]
    public function it_handles_empty_body(): void
    {
        $error = SpooledError::fromResponse(500, '');

        $this->assertInstanceOf(ServerError::class, $error);
        $this->assertStringContainsString('500', $error->getMessage());
    }

    #[Test]
    public function it_extracts_request_id_from_headers(): void
    {
        $headers = [
            'X-Request-Id' => 'req-abc-123',
        ];

        $error = SpooledError::fromResponse(500, '{}', $headers);

        $this->assertSame('req-abc-123', $error->requestId);
    }

    #[Test]
    public function it_extracts_request_id_case_insensitively(): void
    {
        $headers = [
            'x-request-id' => 'req-lowercase',
        ];

        $error = SpooledError::fromResponse(500, '{}', $headers);

        $this->assertSame('req-lowercase', $error->requestId);
    }

    #[Test]
    public function it_parses_rate_limit_headers(): void
    {
        $headers = [
            'Retry-After' => '30',
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + 60),
        ];

        $error = SpooledError::fromResponse(429, '{"message": "Rate limited"}', $headers);

        $this->assertInstanceOf(RateLimitError::class, $error);
        $this->assertSame(30.0, $error->retryAfter);
        $this->assertSame(100, $error->limit);
        $this->assertSame(0, $error->remaining);
    }

    #[Test]
    public function it_converts_error_to_array(): void
    {
        $error = new SpooledError(
            message: 'Test error',
            statusCode: 400,
            errorCode: 'TEST_CODE',
            details: ['field' => 'error'],
            requestId: 'req-123',
        );

        $array = $error->toArray();

        $this->assertSame('Test error', $array['message']);
        $this->assertSame(400, $array['statusCode']);
        $this->assertSame('TEST_CODE', $array['errorCode']);
        $this->assertSame(['field' => 'error'], $array['details']);
        $this->assertSame('req-123', $array['requestId']);
    }

    #[Test]
    public function validation_error_provides_field_errors(): void
    {
        $body = json_encode([
            'message' => 'Validation failed',
            'details' => [
                'fields' => [
                    'email' => ['Email is required', 'Email is invalid'],
                    'name' => ['Name is too short'],
                ],
            ],
        ]);

        $error = SpooledError::fromResponse(422, $body);

        $this->assertInstanceOf(ValidationError::class, $error);
        $fieldErrors = $error->getFieldErrors();

        $this->assertArrayHasKey('email', $fieldErrors);
        $this->assertArrayHasKey('name', $fieldErrors);
    }

    #[Test]
    public function rate_limit_error_calculates_retry_time(): void
    {
        $error = new RateLimitError(
            message: 'Rate limited',
            retryAfter: 30.0,
        );

        $this->assertSame(30.0, $error->getRetryAfterSeconds());
    }

    #[Test]
    public function rate_limit_error_falls_back_to_reset_time(): void
    {
        $resetTime = time() + 45;
        $error = new RateLimitError(
            message: 'Rate limited',
            retryAfter: null,
            reset: $resetTime,
        );

        $retryAfter = $error->getRetryAfterSeconds();
        $this->assertGreaterThan(40, $retryAfter);
        $this->assertLessThanOrEqual(45, $retryAfter);
    }

    #[Test]
    public function rate_limit_error_has_default_retry_time(): void
    {
        $error = new RateLimitError(
            message: 'Rate limited',
        );

        $this->assertSame(60.0, $error->getRetryAfterSeconds());
    }
}
