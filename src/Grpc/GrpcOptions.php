<?php

declare(strict_types=1);

namespace Spooled\Grpc;

/**
 * gRPC client configuration options.
 */
final readonly class GrpcOptions
{
    public function __construct(
        public string $address,
        public ?string $apiKey = null,
        public bool $secure = true,
        public ?float $timeout = null,
    ) {
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
