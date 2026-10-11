<?php

declare(strict_types=1);

namespace Spooled\Util;

/**
 * Normalises the `error` text sent to `POST /jobs/{id}/fail`.
 *
 * The API rejects an empty or over-long message with a 400, which used to leave
 * the job in `processing` (and the real error lost) until its lease expired.
 */
final class FailureText
{
    /** Longest message the API accepts, in characters. */
    public const MAX_LENGTH = 2048;

    /** Used when the handler failed without saying why. */
    public const DEFAULT_MESSAGE = 'Job failed';

    private function __construct()
    {
    }

    /**
     * Return a message the API will accept: never empty, valid UTF-8, at most
     * {@see self::MAX_LENGTH} characters (cut on a character boundary, never
     * mid-way through a multi-byte sequence).
     */
    public static function coerce(?string $text): string
    {
        $text = self::toValidUtf8($text ?? '');
        if (trim($text) === '') {
            return self::DEFAULT_MESSAGE;
        }

        return self::slice($text, self::MAX_LENGTH);
    }

    private static function toValidUtf8(string $text): string
    {
        // Invalid bytes (an upstream HTTP body, binary output) make json_encode
        // fail for the whole request, so the failure would never be recorded.
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $clean = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $text) : false;

        return $clean !== false ? $clean : '';
    }

    private static function slice(string $text, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }

        return preg_match('/^.{0,' . $max . '}/us', $text, $match) === 1 ? $match[0] : $text;
    }
}
