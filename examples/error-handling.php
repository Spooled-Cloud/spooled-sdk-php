<?php

/**
 * Spooled SDK - Error Handling Example
 * 
 * This example demonstrates how to properly handle errors and exceptions
 * when using the Spooled PHP SDK.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;
use Spooled\Errors\SpooledError;
use Spooled\Errors\AuthenticationError;
use Spooled\Errors\NotFoundError;
use Spooled\Errors\ValidationError;
use Spooled\Errors\RateLimitError;
use Spooled\Errors\ConflictError;
use Spooled\Errors\PlanLimitError;
use Spooled\Errors\CircuitBreakerOpenError;
use Spooled\Errors\NetworkError;

// Get API key from environment
$apiKey = getenv('SPOOLED_API_KEY') ?: 'sk_test_your_api_key';

$client = new SpooledClient(
    ClientOptions::fromArray([
        'apiKey' => $apiKey,
        'baseUrl' => getenv('SPOOLED_API_URL') ?: 'https://api.spooled.cloud',
    ])
);

echo "=== Error Handling Examples ===\n\n";

// 1. Handle authentication errors
echo "1. Testing authentication error handling...\n";
try {
    $badClient = new SpooledClient(
        ClientOptions::fromArray(['apiKey' => 'sk_invalid_key'])
    );
    $badClient->jobs->list();
} catch (AuthenticationError $e) {
    echo "   ✓ Caught AuthenticationError: {$e->getMessage()}\n";
    echo "   Status code: {$e->getStatusCode()}\n";
} catch (SpooledError $e) {
    echo "   Caught general error: {$e->getMessage()}\n";
}

// 2. Handle not found errors
echo "\n2. Testing not found error handling...\n";
try {
    $client->jobs->get('non-existent-job-id-12345');
} catch (NotFoundError $e) {
    echo "   ✓ Caught NotFoundError: {$e->getMessage()}\n";
    echo "   Resource type: Job\n";
} catch (SpooledError $e) {
    echo "   Caught general error: {$e->getMessage()}\n";
}

// 3. Handle validation errors
echo "\n3. Testing validation error handling...\n";
try {
    $client->jobs->create([
        // Missing required 'queue' field
        'payload' => ['test' => 'data'],
    ]);
} catch (ValidationError $e) {
    echo "   ✓ Caught ValidationError: {$e->getMessage()}\n";
    if ($e->getErrors()) {
        echo "   Validation errors:\n";
        foreach ($e->getErrors() as $field => $messages) {
            echo "     - {$field}: " . implode(', ', (array)$messages) . "\n";
        }
    }
} catch (SpooledError $e) {
    echo "   Caught general error: {$e->getMessage()}\n";
}

// 4. Handle rate limit errors
echo "\n4. Testing rate limit error handling...\n";
echo "   (Rate limits are enforced per-API-key, hard to trigger in examples)\n";
// Simulated example:
/*
try {
    for ($i = 0; $i < 1000; $i++) {
        $client->jobs->list();
    }
} catch (RateLimitError $e) {
    echo "   ✓ Caught RateLimitError: {$e->getMessage()}\n";
    echo "   Retry after: {$e->getRetryAfter()} seconds\n";
    
    // Wait and retry
    sleep($e->getRetryAfter());
    $client->jobs->list();
}
*/

// 5. Handle plan limit errors
echo "\n5. Testing plan limit error handling...\n";
echo "   (Plan limits depend on your subscription tier)\n";
// Simulated example:
/*
try {
    // Creating more jobs than your plan allows
    for ($i = 0; $i < 1000; $i++) {
        $client->jobs->create([
            'queue' => 'test-queue',
            'payload' => ['index' => $i],
        ]);
    }
} catch (PlanLimitError $e) {
    echo "   ✓ Caught PlanLimitError: {$e->getMessage()}\n";
    echo "   Current plan: {$e->getPlan()}\n";
    echo "   Limit type: {$e->getLimitType()}\n";
    echo "   Current usage: {$e->getCurrentUsage()}\n";
    echo "   Limit: {$e->getLimit()}\n";
}
*/

// 6. Handle conflict errors
echo "\n6. Testing conflict error handling...\n";
try {
    $idempotencyKey = 'unique-key-' . time();
    
    // First request succeeds
    $job1 = $client->jobs->create([
        'queue' => 'test-queue',
        'payload' => ['first' => true],
        'idempotencyKey' => $idempotencyKey,
    ]);
    
    // Second request with same key returns the same job (no conflict)
    $job2 = $client->jobs->create([
        'queue' => 'test-queue',
        'payload' => ['second' => true],  // Different payload
        'idempotencyKey' => $idempotencyKey,
    ]);
    
    echo "   Idempotency key returned same job: {$job1->id} == {$job2->id}\n";
    
    // Cancel to clean up
    $client->jobs->cancel($job1->id);
} catch (ConflictError $e) {
    echo "   ✓ Caught ConflictError: {$e->getMessage()}\n";
} catch (SpooledError $e) {
    echo "   Caught general error: {$e->getMessage()}\n";
}

// 7. Handle circuit breaker errors
echo "\n7. Testing circuit breaker handling...\n";
echo "   (Circuit breaker opens after multiple consecutive failures)\n";
/*
try {
    // After circuit breaker opens due to failures
    $client->jobs->list();
} catch (CircuitBreakerOpenError $e) {
    echo "   ✓ Caught CircuitBreakerOpenError\n";
    echo "   Reset at: " . $e->getResetAt()->format('Y-m-d H:i:s') . "\n";
    
    // Wait for circuit breaker to reset
    sleep($e->getRetryAfter());
    
    // Try again
    $client->jobs->list();
}
*/

// 8. Handle network errors
echo "\n8. Testing network error handling...\n";
echo "   (Network errors occur when the API is unreachable)\n";
/*
try {
    $offlineClient = new SpooledClient(
        ClientOptions::fromArray([
            'apiKey' => 'sk_test_key',
            'baseUrl' => 'https://offline.example.com',
        ])
    );
    $offlineClient->jobs->list();
} catch (NetworkError $e) {
    echo "   ✓ Caught NetworkError: {$e->getMessage()}\n";
    echo "   This is a retryable error: " . ($e->isRetryable() ? 'yes' : 'no') . "\n";
}
*/

// 9. Generic error handling pattern
echo "\n9. Recommended generic error handling pattern:\n";
echo "   See the code below for a comprehensive try-catch structure.\n";

try {
    // Your operation
    $job = $client->jobs->create([
        'queue' => 'example-queue',
        'payload' => ['task' => 'example'],
    ]);
    echo "   ✓ Created job: {$job->id}\n";
    
    // Clean up
    $client->jobs->cancel($job->id);
    
} catch (AuthenticationError $e) {
    // Invalid API key or token
    echo "Authentication failed: {$e->getMessage()}\n";
    // Action: Check API key, refresh token if using JWT
    
} catch (NotFoundError $e) {
    // Resource doesn't exist
    echo "Resource not found: {$e->getMessage()}\n";
    // Action: Check ID, create resource if needed
    
} catch (ValidationError $e) {
    // Invalid request parameters
    echo "Validation failed: {$e->getMessage()}\n";
    // Action: Fix request parameters
    
} catch (RateLimitError $e) {
    // Too many requests
    echo "Rate limited. Retry after: {$e->getRetryAfter()} seconds\n";
    // Action: Wait and retry
    
} catch (PlanLimitError $e) {
    // Plan limits exceeded
    echo "Plan limit reached: {$e->getMessage()}\n";
    // Action: Upgrade plan or reduce usage
    
} catch (ConflictError $e) {
    // Resource conflict (duplicate, etc.)
    echo "Conflict: {$e->getMessage()}\n";
    // Action: Handle the conflict appropriately
    
} catch (CircuitBreakerOpenError $e) {
    // Circuit breaker is open (too many failures)
    echo "Circuit breaker open. Service temporarily unavailable.\n";
    // Action: Wait and retry later
    
} catch (NetworkError $e) {
    // Network connectivity issues
    echo "Network error: {$e->getMessage()}\n";
    // Action: Check connectivity, retry
    
} catch (SpooledError $e) {
    // Any other Spooled SDK error
    echo "Spooled error: {$e->getMessage()}\n";
    echo "Status code: {$e->getStatusCode()}\n";
    echo "Is retryable: " . ($e->isRetryable() ? 'yes' : 'no') . "\n";
    
} catch (\Exception $e) {
    // Unexpected error
    echo "Unexpected error: {$e->getMessage()}\n";
}

// 10. Retry helper
echo "\n10. Example retry helper function:\n";

function withRetry(callable $operation, int $maxRetries = 3, int $baseDelayMs = 1000): mixed
{
    $lastException = null;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            return $operation();
        } catch (RateLimitError $e) {
            // Use Retry-After header if available
            $delay = $e->getRetryAfter() * 1000;
            echo "Rate limited, waiting {$delay}ms...\n";
            usleep($delay * 1000);
            $lastException = $e;
        } catch (NetworkError $e) {
            if (!$e->isRetryable() || $attempt === $maxRetries) {
                throw $e;
            }
            // Exponential backoff
            $delay = $baseDelayMs * pow(2, $attempt - 1);
            echo "Network error, retrying in {$delay}ms...\n";
            usleep($delay * 1000);
            $lastException = $e;
        } catch (SpooledError $e) {
            if (!$e->isRetryable()) {
                throw $e;
            }
            // Exponential backoff
            $delay = $baseDelayMs * pow(2, $attempt - 1);
            echo "Retryable error, retrying in {$delay}ms...\n";
            usleep($delay * 1000);
            $lastException = $e;
        }
    }
    
    throw $lastException;
}

// Example usage:
/*
$job = withRetry(function () use ($client) {
    return $client->jobs->create([
        'queue' => 'my-queue',
        'payload' => ['data' => 'value'],
    ]);
});
*/

echo "\n=== Error Handling Examples Complete ===\n";
