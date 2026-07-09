<?php

declare(strict_types=1);

namespace Spooled\Grpc;

/**
 * gRPC client configuration options.
 */
final readonly class GrpcOptions
{
    public ?string $apiKey;

    public function __construct(
        public string $address,
        ?string $apiKey = null,
        public bool $secure = true,
        public ?float $timeout = null,
    ) {
        // gRPC metadata (like HTTP headers) rejects '\r' / '\n', so keys read
        // from environment variables with a trailing newline blow up the
        // transport with an opaque error. Trim once at construction so every
        // call site sees a well-formed key.
        $this->apiKey = self::trimCredential($apiKey);
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            address: (string) ($data['address'] ?? 'localhost:50051'),
            apiKey: isset($data['apiKey']) ? (string) $data['apiKey'] : null,
            secure: (bool) ($data['secure'] ?? true),
            timeout: isset($data['timeout']) ? (float) $data['timeout'] : null,
        );
    }

    private static function trimCredential(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Check if using localhost (for insecure default).
     */
    public function isLocalhost(): bool
    {
        return str_starts_with($this->address, 'localhost:') ||
               str_starts_with($this->address, '127.0.0.1:') ||
               str_starts_with($this->address, '[::1]:');
    }
}
