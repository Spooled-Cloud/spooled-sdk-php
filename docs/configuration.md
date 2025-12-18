# Configuration Guide

This guide covers all configuration options for the Spooled PHP SDK.

## Client Configuration

```php
use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;
use Spooled\Config\CircuitBreakerConfig;

$client = new SpooledClient(
    ClientOptions::fromArray([
        // === Authentication (required - one of these) ===
        'apiKey' => 'sk_live_...',           // API key (starts with sk_live_ or sk_test_)
        'accessToken' => 'jwt_token',         // Or JWT access token
        'refreshToken' => 'refresh_token',    // Optional: for auto token renewal
        'adminKey' => 'admin_...',            // Optional: for admin endpoints

        // === API Settings ===
        'baseUrl' => 'https://api.spooled.cloud',  // REST API base URL (default)
        'wsUrl' => 'wss://api.spooled.cloud',      // WebSocket URL for realtime
        'grpcAddress' => 'grpc.spooled.cloud:443', // gRPC server address (default)
        'timeout' => 30,                           // Request timeout in seconds (default: 30)

        // === Retry Configuration ===
        'retry' => RetryConfig::fromArray([
            'maxRetries' => 3,                // Max retry attempts (default: 3)
            'baseDelay' => 1000,              // Base delay in ms (default: 1000)
            'maxDelay' => 30000,              // Max delay cap in ms (default: 30000)
            'multiplier' => 2.0,              // Exponential backoff factor (default: 2.0)
            'jitter' => true,                 // Add randomness to delays (default: true)
        ]),

        // === Circuit Breaker ===
        'circuitBreaker' => CircuitBreakerConfig::fromArray([
            'enabled' => true,                // Enable circuit breaker (default: true)
            'failureThreshold' => 5,          // Failures to open circuit (default: 5)
            'successThreshold' => 3,          // Successes to close circuit (default: 3)
            'timeout' => 30000,               // Time before retry after open (default: 30000ms)
        ]),

        // === Advanced ===
        'headers' => [                        // Custom headers for all requests
            'X-Custom-Header' => 'value',
        ],
        'userAgent' => 'my-app/1.0.0',        // Custom user agent
    ])
);
```

## Environment Variables

The SDK can read configuration from environment variables:

```bash
# Required
SPOOLED_API_KEY=sk_live_your_api_key

# Optional
SPOOLED_API_URL=https://api.spooled.cloud
SPOOLED_WS_URL=wss://api.spooled.cloud
SPOOLED_GRPC_ADDRESS=grpc.spooled.cloud:443
SPOOLED_TIMEOUT=30
```

Example usage:

```php
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => getenv('SPOOLED_API_KEY'),
        'baseUrl' => getenv('SPOOLED_API_URL') ?: 'https://api.spooled.cloud',
        'grpcAddress' => getenv('SPOOLED_GRPC_ADDRESS') ?: 'grpc.spooled.cloud:443',
    ])
);
```

## Self-Hosted Deployment

If you're running your own Spooled instance:

```php
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_your_key',
        
        // Point to your self-hosted instance
        'baseUrl' => 'https://spooled.your-company.com',
        
        // WebSocket URL (if different from REST)
        'wsUrl' => 'wss://spooled.your-company.com',
        
        // gRPC address (optional - only if using gRPC workers)
        'grpcAddress' => 'grpc.your-company.com:443',
    ])
);
```

### Local Development

```php
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_test_local_dev_key',
        'baseUrl' => 'http://localhost:8080',
        'grpcAddress' => 'localhost:50051',
    ])
);
```

## Retry Behavior

The SDK uses exponential backoff with jitter for transient failures:

```
delay = min(maxDelay, baseDelay * (multiplier ^ attempt)) * (1 + random * jitter)
```

| Attempt | Base Delay | With Jitter (approx) |
|---------|------------|----------------------|
| 1       | 1000ms     | 1000-1500ms          |
| 2       | 2000ms     | 2000-3000ms          |
| 3       | 4000ms     | 4000-6000ms          |

### Retryable Errors

By default, these errors trigger retries:

- Network errors (connection refused, DNS failures)
- Timeout errors
- 429 Too Many Requests (respects `Retry-After` header)
- 5xx Server errors

Non-retryable errors:

- 400 Bad Request
- 401 Unauthorized
- 403 Forbidden
- 404 Not Found
- 409 Conflict

### Custom Retry Configuration

```php
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_...',
        'retry' => RetryConfig::fromArray([
            'maxRetries' => 5,
            'baseDelay' => 500,
            'maxDelay' => 60000,
            'multiplier' => 3.0,
        ]),
    ])
);
```

## Circuit Breaker

The circuit breaker prevents cascading failures by temporarily blocking requests after repeated failures.

### States

```
CLOSED → (failures >= threshold) → OPEN → (timeout expires) → HALF_OPEN
                                                                    ↓
                              CLOSED ← (successes >= threshold) ←──┘
                                                                    ↓
                              OPEN ← (any failure) ←───────────────┘
```

### Configuration

```php
$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_...',
        'circuitBreaker' => CircuitBreakerConfig::fromArray([
            'enabled' => true,
            'failureThreshold' => 5,    // Open after 5 consecutive failures
            'successThreshold' => 3,    // Close after 3 consecutive successes
            'timeout' => 30000,         // Try again after 30 seconds
        ]),
    ])
);
```

### Handling Circuit Breaker Errors

```php
use Spooled\Errors\CircuitBreakerOpenError;

try {
    $job = $client->jobs->create([
        'queue' => 'emails',
        'payload' => ['to' => 'user@example.com'],
    ]);
} catch (CircuitBreakerOpenError $e) {
    echo "Circuit breaker is open, try again later\n";
    echo "Will reset at: " . $e->resetAt->format('Y-m-d H:i:s') . "\n";
}
```

### Checking Circuit Breaker Status

```php
$stats = $client->getCircuitBreakerStats();
echo "State: " . $stats['state'] . "\n";        // 'closed', 'open', or 'half-open'
echo "Failures: " . $stats['failures'] . "\n";
echo "Successes: " . $stats['successes'] . "\n";
```

### Resetting Circuit Breaker

```php
// Reset after fixing the underlying issue
$client->resetCircuitBreaker();
```

## Multiple Clients

You can create multiple clients for different environments or API keys:

```php
// Spooled Cloud (production)
$cloudClient = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_production_key',
    ])
);

// Self-hosted instance
$selfHostedClient = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_self_hosted_key',
        'baseUrl' => 'https://spooled.your-company.com',
        'grpcAddress' => 'grpc.your-company.com:443',
    ])
);

// Local development
$localClient = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_test_dev_key',
        'baseUrl' => 'http://localhost:8080',
        'grpcAddress' => 'localhost:50051',
    ])
);

// Admin client
$adminClient = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => 'sk_live_...',
        'adminKey' => 'admin_super_secret',
    ])
);
```

## Laravel Integration

```php
// config/spooled.php
return [
    'api_key' => env('SPOOLED_API_KEY'),
    'base_url' => env('SPOOLED_API_URL', 'https://api.spooled.cloud'),
    'grpc_address' => env('SPOOLED_GRPC_ADDRESS', 'grpc.spooled.cloud:443'),
    'timeout' => env('SPOOLED_TIMEOUT', 30),
];

// app/Providers/SpooledServiceProvider.php
use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

$this->app->singleton(SpooledClient::class, function ($app) {
    return new SpooledClient(
        ClientOptions::fromArray([
            'apiKey' => config('spooled.api_key'),
            'baseUrl' => config('spooled.base_url'),
            'grpcAddress' => config('spooled.grpc_address'),
            'timeout' => config('spooled.timeout'),
        ])
    );
});
```

## Symfony Integration

```yaml
# config/services.yaml
parameters:
    spooled.api_key: '%env(SPOOLED_API_KEY)%'
    spooled.base_url: '%env(SPOOLED_API_URL)%'

services:
    Spooled\SpooledClient:
        factory: ['Spooled\SpooledClient', 'create']
        arguments:
            - apiKey: '%spooled.api_key%'
              baseUrl: '%spooled.base_url%'
```

## Cleanup

Close the client when done to release resources:

```php
// Close the client (closes gRPC connections, etc.)
$client->close();
```

