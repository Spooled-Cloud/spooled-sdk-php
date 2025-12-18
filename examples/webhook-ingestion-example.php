<?php

declare(strict_types=1);

/**
 * Webhook ingestion example for the Spooled PHP SDK.
 *
 * This demonstrates how to validate and process incoming webhooks
 * from GitHub, Stripe, and custom sources.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

// Create client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
    baseUrl: getenv('BASE_URL') ?: 'https://api.spooled.cloud',
));

echo "=== Webhook Ingestion Example ===\n\n";

// ============================================================================
// 1. GitHub Webhook Validation
// ============================================================================
echo "1. GitHub Webhook Validation\n";
echo str_repeat('-', 40) . "\n";

$githubPayload = json_encode([
    'action' => 'push',
    'ref' => 'refs/heads/main',
    'commits' => [
        ['id' => 'abc123', 'message' => 'Fix bug'],
    ],
]);

$githubSecret = 'your-github-webhook-secret';

// Generate signature (in production, this comes from GitHub in X-Hub-Signature-256 header)
$signature = $client->ingest->generateGitHubSignature($githubPayload, $githubSecret);
echo "Generated signature: {$signature}\n";

// Validate signature
$isValid = $client->ingest->validateGitHubSignature($githubPayload, $signature, $githubSecret);
echo "Signature valid: " . ($isValid ? 'Yes ✅' : 'No ❌') . "\n";

// Test with invalid signature
$invalidValid = $client->ingest->validateGitHubSignature($githubPayload, 'sha256=invalid', $githubSecret);
echo "Invalid signature detected: " . ($invalidValid ? 'No ❌' : 'Yes ✅') . "\n\n";

// ============================================================================
// 2. Stripe Webhook Validation
// ============================================================================
echo "2. Stripe Webhook Validation\n";
echo str_repeat('-', 40) . "\n";

$stripePayload = json_encode([
    'id' => 'evt_1234567890',
    'type' => 'payment_intent.succeeded',
    'data' => [
        'object' => [
            'id' => 'pi_1234567890',
            'amount' => 2000,
            'currency' => 'usd',
        ],
    ],
]);

$stripeSecret = 'whsec_test_secret';

// Generate signature with timestamp (in production, this comes from Stripe-Signature header)
$signature = $client->ingest->generateStripeSignature($stripePayload, $stripeSecret);
echo "Generated signature: {$signature}\n";

// Validate signature
$isValid = $client->ingest->validateStripeSignature($stripePayload, $signature, $stripeSecret);
echo "Signature valid: " . ($isValid ? 'Yes ✅' : 'No ❌') . "\n";

// Test with expired timestamp (more than 5 minutes old)
$expiredTimestamp = time() - 600; // 10 minutes ago
$expiredSignature = $client->ingest->generateStripeSignature($stripePayload, $stripeSecret, $expiredTimestamp);
$isExpiredValid = $client->ingest->validateStripeSignature($stripePayload, $expiredSignature, $stripeSecret, 300);
echo "Expired signature rejected: " . ($isExpiredValid ? 'No ❌' : 'Yes ✅') . "\n\n";

// ============================================================================
// 3. Custom Webhook Ingestion (via API)
// ============================================================================
echo "3. Custom Webhook Ingestion\n";
echo str_repeat('-', 40) . "\n";

// Note: These methods require proper authentication to your organization
// and use the /webhooks/{orgId}/custom endpoint

try {
    // Get organization ID first
    $orgs = $client->organizations->list();
    if (count($orgs) > 0) {
        $orgId = $orgs[0]->id;
        echo "Using organization: {$orgId}\n";

        // Ingest a custom webhook
        // This creates a job from the webhook payload
        $result = $client->ingest->custom($orgId, [
            'queueName' => 'custom-webhooks',
            'eventType' => 'user.created',
            'payload' => [
                'userId' => '12345',
                'email' => 'user@example.com',
                'createdAt' => date('c'),
            ],
        ]);

        echo "Created job from webhook: {$result['jobId']}\n";
    } else {
        echo "No organizations found.\n";
    }
} catch (\Throwable $e) {
    echo "Custom webhook ingestion: {$e->getMessage()}\n";
    echo "(This is expected if webhook endpoint is not configured)\n";
}

echo "\n";

// ============================================================================
// 4. Example: Processing a GitHub Webhook in a Request Handler
// ============================================================================
echo "4. Example Request Handler (pseudo-code)\n";
echo str_repeat('-', 40) . "\n";

echo <<<'CODE'
<?php
// In your webhook endpoint handler (e.g., /webhooks/github)

// Get raw payload
$payload = file_get_contents('php://input');

// Get signature from header
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

// Validate
if (!$client->ingest->validateGitHubSignature($payload, $signature, $secret)) {
    http_response_code(401);
    exit('Invalid signature');
}

// Process based on event type
switch ($event) {
    case 'push':
        // Create a job to process the push
        $client->jobs->create([
            'queue' => 'github-events',
            'payload' => json_decode($payload, true),
        ]);
        break;
    case 'pull_request':
        // Handle pull request
        break;
}

http_response_code(200);
echo 'OK';

CODE;

echo "\n\nDone!\n";

