<?php

declare(strict_types=1);

namespace Spooled\Errors;

use Throwable;

/**
 * Error thrown when validation fails.
 *
 * The production backend returns HTTP 400 (code VALIDATION_ERROR) for job and
 * queue validation failures, while other endpoints may return 422. Both map to
 * this error type, so the real HTTP status is preserved via $statusCode.
 */
final class ValidationError extends SpooledError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Validation failed',
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?string $rawBody = null,
        ?Throwable $previous = null,
        int $statusCode = 422,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $details, $requestId, $rawBody, $previous);
    }

    /**
     * Validation messages grouped by field: `['queue_name' => ['message', ...]]`.
     *
     * The API sends `details` as a list of `{field, message, code}` entries;
     * each entry becomes one message under its field. A `details.fields` map
     * (`field => message|messages`) and a plain `field => message|messages`
     * map are accepted too. An entry without a field is grouped under `''`.
     * The raw `details` are available from getDetails().
     *
     * @return array<string, list<string>>
     */
    public function getFieldErrors(): array
    {
        $source = $this->details;
        if (isset($source['fields']) && is_array($source['fields'])) {
            $source = $source['fields'];
        }

        $fieldErrors = [];
        foreach ($source as $key => $entry) {
            if (is_array($entry) && (array_key_exists('field', $entry) || array_key_exists('message', $entry))) {
                $field = is_scalar($entry['field'] ?? null) ? (string) $entry['field'] : '';
                $message = $entry['message'] ?? $entry['code'] ?? null;
                $fieldErrors[$field][] = is_scalar($message) ? (string) $message : 'Invalid value';

                continue;
            }

            $field = is_string($key) ? $key : '';
            $messages = is_array($entry) ? $entry : [$entry];
            foreach ($messages as $message) {
                if (is_scalar($message)) {
                    $fieldErrors[$field][] = (string) $message;
                }
            }
        }

        return $fieldErrors;
    }

    /**
     * The raw `details` of the error response, as the API sent them (for a
     * validation failure, a list of `{field, message, code}` entries).
     *
     * @return array<array-key, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
