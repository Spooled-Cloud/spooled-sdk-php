<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Util\Casing;

#[CoversClass(Casing::class)]
final class CasingTest extends TestCase
{
    #[Test]
    #[DataProvider('snakeCaseProvider')]
    public function it_converts_to_snake_case(string $input, string $expected): void
    {
        $this->assertSame($expected, Casing::toSnakeCase($input));
    }

    public static function snakeCaseProvider(): array
    {
        return [
            'simple camelCase' => ['camelCase', 'camel_case'],
            'PascalCase' => ['PascalCase', 'pascal_case'],
            'multiple words' => ['someMultiWordString', 'some_multi_word_string'],
            'already snake_case' => ['already_snake', 'already_snake'],
            'single word lowercase' => ['word', 'word'],
            'single word uppercase' => ['WORD', 'word'], // All uppercase becomes lowercase
            'empty string' => ['', ''],
            'with numbers' => ['job123Status', 'job123status'], // Numbers don't trigger underscore
            'apiKey' => ['apiKey', 'api_key'],
            'userId' => ['userId', 'user_id'],
            'createdAt' => ['createdAt', 'created_at'],
            'maxRetries' => ['maxRetries', 'max_retries'],
        ];
    }

    #[Test]
    #[DataProvider('camelCaseProvider')]
    public function it_converts_to_camel_case(string $input, string $expected): void
    {
        $this->assertSame($expected, Casing::toCamelCase($input));
    }

    public static function camelCaseProvider(): array
    {
        return [
            'simple snake_case' => ['snake_case', 'snakeCase'],
            'multiple words' => ['some_multi_word_string', 'someMultiWordString'],
            'already camelCase' => ['alreadyCamel', 'alreadyCamel'],
            'single word' => ['word', 'word'],
            'empty string' => ['', ''],
            'with numbers' => ['job_123_status', 'job123Status'],
            'api_key' => ['api_key', 'apiKey'],
            'user_id' => ['user_id', 'userId'],
            'created_at' => ['created_at', 'createdAt'],
            'max_retries' => ['max_retries', 'maxRetries'],
        ];
    }

    #[Test]
    public function it_converts_array_keys_to_snake_case(): void
    {
        $input = [
            'userId' => '123',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'createdAt' => '2024-01-01',
        ];

        $expected = [
            'user_id' => '123',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_at' => '2024-01-01',
        ];

        $this->assertSame($expected, Casing::keysToSnakeCase($input));
    }

    #[Test]
    public function it_converts_nested_array_keys_to_snake_case(): void
    {
        $input = [
            'userId' => '123',
            'userProfile' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'contactInfo' => [
                    'emailAddress' => 'john@example.com',
                    'phoneNumber' => '1234567890',
                ],
            ],
        ];

        $expected = [
            'user_id' => '123',
            'user_profile' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'contact_info' => [
                    'email_address' => 'john@example.com',
                    'phone_number' => '1234567890',
                ],
            ],
        ];

        $this->assertSame($expected, Casing::keysToSnakeCase($input));
    }

    #[Test]
    public function it_converts_array_keys_to_camel_case(): void
    {
        $input = [
            'user_id' => '123',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_at' => '2024-01-01',
        ];

        $expected = [
            'userId' => '123',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'createdAt' => '2024-01-01',
        ];

        $this->assertSame($expected, Casing::keysToCamelCase($input));
    }

    #[Test]
    public function it_converts_nested_array_keys_to_camel_case(): void
    {
        $input = [
            'user_id' => '123',
            'user_profile' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'contact_info' => [
                    'email_address' => 'john@example.com',
                    'phone_number' => '1234567890',
                ],
            ],
        ];

        $expected = [
            'userId' => '123',
            'userProfile' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'contactInfo' => [
                    'emailAddress' => 'john@example.com',
                    'phoneNumber' => '1234567890',
                ],
            ],
        ];

        $this->assertSame($expected, Casing::keysToCamelCase($input));
    }

    #[Test]
    public function it_handles_arrays_of_objects(): void
    {
        $input = [
            'userList' => [
                ['userId' => '1', 'userName' => 'Alice'],
                ['userId' => '2', 'userName' => 'Bob'],
            ],
        ];

        $expected = [
            'user_list' => [
                ['user_id' => '1', 'user_name' => 'Alice'],
                ['user_id' => '2', 'user_name' => 'Bob'],
            ],
        ];

        $this->assertSame($expected, Casing::keysToSnakeCase($input));
    }

    #[Test]
    public function it_preserves_non_string_values(): void
    {
        $input = [
            'intValue' => 42,
            'floatValue' => 3.14,
            'boolValue' => true,
            'nullValue' => null,
            'arrayValue' => [1, 2, 3],
        ];

        $result = Casing::keysToSnakeCase($input);

        $this->assertSame(42, $result['int_value']);
        $this->assertSame(3.14, $result['float_value']);
        $this->assertTrue($result['bool_value']);
        $this->assertNull($result['null_value']);
        $this->assertSame([1, 2, 3], $result['array_value']);
    }

    #[Test]
    public function it_handles_empty_arrays(): void
    {
        $this->assertSame([], Casing::keysToSnakeCase([]));
        $this->assertSame([], Casing::keysToCamelCase([]));
    }

    #[Test]
    public function it_handles_numeric_keys(): void
    {
        $input = [
            0 => 'first',
            1 => 'second',
            'stringKey' => 'value',
        ];

        $expected = [
            0 => 'first',
            1 => 'second',
            'string_key' => 'value',
        ];

        $this->assertSame($expected, Casing::keysToSnakeCase($input));
    }
}
