<?php

declare(strict_types=1);

namespace Spooled\Worker;

/**
 * Worker configuration options.
 *
 * Matches Node.js SDK SpooledWorkerOptions.
 */
final readonly class WorkerConfig
{
    public const DEFAULT_CONCURRENCY = 5;

    public const DEFAULT_POLL_INTERVAL = 1000; // ms

    public const DEFAULT_LEASE_DURATION = 30; // seconds

    public const DEFAULT_HEARTBEAT_FRACTION = 0.5;

    public const DEFAULT_SHUTDOWN_TIMEOUT = 30000; // ms

    public const DEFAULT_HEARTBEAT_INTERVAL = 15000; // ms

    public function __construct(
        /** Queue name to process jobs from */
        public string $queueName,
        /** Maximum concurrent jobs */
        public int $concurrency = self::DEFAULT_CONCURRENCY,
        /** Poll interval in milliseconds */
        public int $pollInterval = self::DEFAULT_POLL_INTERVAL,
        /** Lease duration in seconds */
        public int $leaseDuration = self::DEFAULT_LEASE_DURATION,
        /** Heartbeat fraction of lease duration */
        public float $heartbeatFraction = self::DEFAULT_HEARTBEAT_FRACTION,
        /** Shutdown timeout in milliseconds */
        public int $shutdownTimeout = self::DEFAULT_SHUTDOWN_TIMEOUT,
        /** Worker heartbeat interval in milliseconds */
        public int $heartbeatInterval = self::DEFAULT_HEARTBEAT_INTERVAL,
        /** Worker hostname */
        public ?string $hostname = null,
        /** Worker type identifier */
        public string $workerType = 'php',
        /** Worker version */
        public ?string $version = null,
        /** Additional metadata */
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
        /** Auto-start worker after construction */
        public bool $autoStart = false,
    ) {
    }

    /**
     * Create from array (for convenience).
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            queueName: (string) ($options['queueName'] ?? $options['queue'] ?? ''),
            concurrency: (int) ($options['concurrency'] ?? self::DEFAULT_CONCURRENCY),
            pollInterval: (int) ($options['pollInterval'] ?? self::DEFAULT_POLL_INTERVAL),
            leaseDuration: (int) ($options['leaseDuration'] ?? self::DEFAULT_LEASE_DURATION),
            heartbeatFraction: (float) ($options['heartbeatFraction'] ?? self::DEFAULT_HEARTBEAT_FRACTION),
            shutdownTimeout: (int) ($options['shutdownTimeout'] ?? self::DEFAULT_SHUTDOWN_TIMEOUT),
            heartbeatInterval: (int) ($options['heartbeatInterval'] ?? self::DEFAULT_HEARTBEAT_INTERVAL),
            hostname: isset($options['hostname']) ? (string) $options['hostname'] : null,
            workerType: (string) ($options['workerType'] ?? 'php'),
            version: isset($options['version']) ? (string) $options['version'] : null,
            metadata: isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : null,
            autoStart: (bool) ($options['autoStart'] ?? false),
        );
    }
}
