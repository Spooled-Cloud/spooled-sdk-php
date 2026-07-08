<?php

declare(strict_types=1);

namespace Spooled\Util;

/**
 * Utility class for converting between camelCase and snake_case.
 */
final class Casing
{
    /**
     * Keys whose values are opaque, user-defined JSON and must be transmitted
     * byte-for-byte. Only the key itself is re-cased; the value subtree is left
     * untouched in both directions.
     *
     * Mirrors the Node SDK's SKIP_CONVERSION_KEYS. Both camelCase and snake_case
     * spellings are listed so the check works on the request (camel) and the
     * response (snake) side.
     *
     * @var array<string, true>
     */
    private const SKIP_CONVERSION_KEYS = [
        'payload' => true,
        'result' => true,
        'metadata' => true,
        'tags' => true,
        'settings' => true,
        'details' => true,
        'extra' => true,
        'payloadTemplate' => true,
        'payload_template' => true,
        'customLimits' => true,
        'custom_limits' => true,
    ];

    /**
     * Convert a string from camelCase to snake_case.
     */
    public static function toSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input) ?? $input);
    }

    /**
     * Convert a string from snake_case to camelCase.
     */
    public static function toCamelCase(string $input): string
    {
        $result = str_replace('_', '', ucwords($input, '_'));

        return lcfirst($result);
    }

    /**
     * Convert array keys from camelCase to snake_case recursively.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function keysToSnakeCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? self::toSnakeCase($key) : $key;

            // Opaque user data (payload/result/metadata/...) is preserved
            // byte-for-byte: rename the envelope key but never recurse the value.
            if (self::shouldSkipConversion($key, $newKey)) {
                $result[$newKey] = $value;

                continue;
            }

            if (is_array($value)) {
                // Check if it's an associative array or a list
                if (self::isAssociativeArray($value)) {
                    $value = self::keysToSnakeCase($value);
                } else {
                    // It's a list, process each item
                    $value = array_map(function ($item) {
                        return is_array($item) && self::isAssociativeArray($item)
                            ? self::keysToSnakeCase($item)
                            : $item;
                    }, $value);
                }
            }

            $result[$newKey] = $value;
        }

        return $result;
    }

    /**
     * Convert array keys from snake_case to camelCase recursively.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function keysToCamelCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? self::toCamelCase($key) : $key;

            // Opaque user data (payload/result/metadata/...) is preserved
            // byte-for-byte: rename the envelope key but never recurse the value.
            if (self::shouldSkipConversion($key, $newKey)) {
                $result[$newKey] = $value;

                continue;
            }

            if (is_array($value)) {
                // Check if it's an associative array or a list
                if (self::isAssociativeArray($value)) {
                    $value = self::keysToCamelCase($value);
                } else {
                    // It's a list, process each item
                    $value = array_map(function ($item) {
                        return is_array($item) && self::isAssociativeArray($item)
                            ? self::keysToCamelCase($item)
                            : $item;
                    }, $value);
                }
            }

            $result[$newKey] = $value;
        }

        return $result;
    }

    /**
     * Determine whether the value under a key is opaque, pass-through data
     * whose nested keys must not be re-cased. The original and converted key
     * spellings are both checked so it works in both conversion directions.
     */
    private static function shouldSkipConversion(int|string $key, int|string $newKey): bool
    {
        return (is_string($key) && isset(self::SKIP_CONVERSION_KEYS[$key]))
            || (is_string($newKey) && isset(self::SKIP_CONVERSION_KEYS[$newKey]));
    }

    /**
     * Check if an array is associative (has string keys).
     *
     * @param array<mixed> $array
     */
    private static function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
