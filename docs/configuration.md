# Configuration Guide

This guide documents the configuration surface in Spooled PHP SDK 1.0.20.

## Client Configuration

`ClientOptions`, `RetryConfig`, and `CircuitBreakerConfig` are constructed directly with named arguments. They do not provide `fromArray()` factories.

```php
<?php

use Spooled\Config\CircuitBreakerConfig;
use Spooled\Config\ClientOptions;
use Spooled\Config\RetryConfig;
use Spooled\SpooledClient;

$client = new SpooledClient(new ClientOptions(
    // Configure at least one credential.
    apiKey: 'sp_live_...',
    // accessToken: 'jwt-token',
    // refreshToken: 'refresh-token',
    // adminKey: 'admin-key',

    baseUrl: 'https://api.spooled.cloud',
    // wsUrl defaults to the WebSocket form of baseUrl.
    grpcAddress: 'grpc.spooled.cloud:443',

    connectTimeout: 10.0,
    requestTimeout: 30.0,

    retry: new RetryConfig(
        maxRetries: 3,
        baseDelay: 1.0,
        maxDelay: 30.0,
        factor: 2.0,
        jitter: 0.1,
    ),
    circuitBreaker: new CircuitBreakerConfig(
        enabled: true,
        failureThreshold: 5,
        successThreshold: 2,
        timeout: 30.0,
    ),

    userAgent: 'my-app/1.0.0',
    headers: ['X-Custom-Header' => 'value'],
    // logger: $psr3Logger, // Optional: pass an application-provided PSR-3 logger.
));
```

The default `User-Agent` is `spooled-php/1.0.20`, derived from `Spooled\Version::VERSION`.

## Environment Variables

The SDK does not implicitly load environment variables. Read them in your application and pass the values to `ClientOptions`:

```php
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('SPOOLED_API_KEY') ?: null,
    baseUrl: getenv('SPOOLED_BASE_URL') ?: 'https://api.spooled.cloud',
    wsUrl: getenv('SPOOLED_WS_URL') ?: null,
    grpcAddress: getenv('SPOOLED_GRPC_ADDRESS') ?: 'grpc.spooled.cloud:443',
    requestTimeout: (float) (getenv('SPOOLED_REQUEST_TIMEOUT') ?: 30),
));
```

Credentials are trimmed when options are constructed, so a trailing newline from a file or environment variable is removed.

## Retry Behavior

The default retry policy uses exponential backoff in seconds with proportional ±10% jitter. For zero-indexed `attempt`, the implementation is:

```text
base = baseDelay * factor^attempt
jittered = base + uniform(-1, 1) * base * jitter
delay = min(max(jittered, 0), maxDelay)
```

With the default `jitter: 0.1`, an unclamped 2-second base delay becomes 1.8–2.2 seconds. `Retry-After` takes precedence and is capped at `maxDelay` without random jitter. Network failures, timeouts, HTTP 429, and HTTP 5xx responses are retryable. Non-idempotent POST requests are retried only when the request is safe to repeat, such as when an idempotency key is supplied.

Disable retries explicitly when needed:

```php
$options = new ClientOptions(
    apiKey: 'sp_live_...',
    retry: RetryConfig::disabled(),
);
```

## Circuit Breaker

The circuit breaker counts transient failures (HTTP 429, HTTP 5xx, network errors, and timeouts), not ordinary HTTP 4xx responses.

```php
$options = new ClientOptions(
    apiKey: 'sp_live_...',
    circuitBreaker: new CircuitBreakerConfig(
        failureThreshold: 5,
        successThreshold: 2,
        timeout: 30.0,
    ),
);

$stats = $client->getCircuitBreakerStats();
$client->resetCircuitBreaker();
```

Use `CircuitBreakerConfig::disabled()` to disable it.

## Self-Hosted and Local Endpoints

```php
$selfHosted = new SpooledClient(new ClientOptions(
    apiKey: 'sp_live_...',
    baseUrl: 'https://spooled.example.com',
    wsUrl: 'wss://spooled.example.com',
    grpcAddress: 'grpc.spooled.example.com:443',
));

$local = new SpooledClient(new ClientOptions(
    apiKey: 'sp_test_...',
    baseUrl: 'http://localhost:8080',
    grpcAddress: 'localhost:50051',
));
```

For standalone gRPC configuration, use `GrpcOptions` as shown in [grpc.md](grpc.md).

## Laravel Integration

Define application settings in `config/spooled.php`:

```php
<?php

return [
    'api_key' => env('SPOOLED_API_KEY'),
    'base_url' => env('SPOOLED_BASE_URL', 'https://api.spooled.cloud'),
    'grpc_address' => env('SPOOLED_GRPC_ADDRESS', 'grpc.spooled.cloud:443'),
    'request_timeout' => (float) env('SPOOLED_REQUEST_TIMEOUT', 30),
];
```

Register the client as a singleton in a service provider:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spooled\Config\ClientOptions;
use Spooled\SpooledClient;

final class SpooledServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SpooledClient::class, static fn (): SpooledClient =>
            new SpooledClient(new ClientOptions(
                apiKey: config('spooled.api_key'),
                baseUrl: config('spooled.base_url'),
                grpcAddress: config('spooled.grpc_address'),
                requestTimeout: (float) config('spooled.request_timeout'),
            ))
        );
    }
}
```

Type-hint `SpooledClient` in controllers, jobs, or services to resolve it from the container. Ensure `SPOOLED_API_KEY` is set before caching configuration with `php artisan config:cache`.

## Symfony Integration

Because `SpooledClient` accepts a `ClientOptions` object, define both services explicitly:

```yaml
# config/services.yaml
services:
    Spooled\Config\ClientOptions:
        arguments:
            $apiKey: '%env(SPOOLED_API_KEY)%'
            $baseUrl: '%env(default:spooled_default_base_url:SPOOLED_BASE_URL)%'
            $grpcAddress: '%env(default:spooled_default_grpc_address:SPOOLED_GRPC_ADDRESS)%'
            $requestTimeout: '%env(default:spooled_default_timeout:float:SPOOLED_REQUEST_TIMEOUT)%'

    Spooled\SpooledClient:
        arguments:
            $options: '@Spooled\Config\ClientOptions'

parameters:
    spooled_default_base_url: 'https://api.spooled.cloud'
    spooled_default_grpc_address: 'grpc.spooled.cloud:443'
    spooled_default_timeout: 30
```

You can then autowire `SpooledClient` into application services. If your Symfony version or deployment does not use env defaults, set all four environment variables and reference them directly instead.

## Cleanup

```php
$client->close();
```

Closing releases lazily created realtime and gRPC resources.