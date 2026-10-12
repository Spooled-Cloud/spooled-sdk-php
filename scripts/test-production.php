#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Production test runner for scripts/test-local.php.
 *
 * Safe defaults:
 * - Uses Spooled Cloud endpoints
 * - Skips stress/load tests unless --full is provided (to avoid noisy prod runs)
 * - Never runs the tests with side effects outside the suite's own `test-`
 *   queues (organization sign-up, email login, billing portal, webhook token
 *   regeneration): RUN_UNSAFE_TESTS is forced off
 *
 * Use an API key of a dedicated test organization: the suite creates and
 * cancels jobs, workers, schedules, workflows, webhooks and an API key.
 *
 * Usage:
 *   API_KEY=sp_live_... php scripts/test-production.php
 *
 * Options:
 *   --full     Run stress/load tests too (NOT recommended for prod)
 *   --verbose  Enable verbose output in test-local.php
 *
 * Overrides (optional):
 *   BASE_URL=https://api.spooled.cloud
 *   GRPC_ADDRESS=grpc.spooled.cloud:443
 *   SKIP_GRPC=0|1
 *   SKIP_STRESS=0|1
 */
$argv = array_slice($GLOBALS['argv'], 1);
$isFull = in_array('--full', $argv, true);
$isVerbose = in_array('--verbose', $argv, true);

$apiKey = getenv('API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "❌ API_KEY is required\n");
    fwrite(STDERR, "   Example: API_KEY=sp_live_... php scripts/test-production.php\n");
    exit(1);
}

// Production defaults
$baseUrl = getenv('BASE_URL') ?: 'https://api.spooled.cloud';
$grpcAddress = getenv('GRPC_ADDRESS') ?: 'grpc.spooled.cloud:443';

// Default to running gRPC tests in production (can be overridden)
$skipGrpc = getenv('SKIP_GRPC') !== false ? getenv('SKIP_GRPC') : '0';
// Default to skipping stress tests in production (can be overridden or --full)
$skipStress = $isFull ? '0' : (getenv('SKIP_STRESS') !== false ? getenv('SKIP_STRESS') : '1');

// The child inherits the whole environment (Windows needs SystemRoot and
// friends for sockets and TLS), with the production settings on top.
$env = array_merge(getenv(), [
    'API_KEY' => $apiKey,
    'BASE_URL' => $baseUrl,
    'GRPC_ADDRESS' => $grpcAddress,
    'SKIP_GRPC' => $skipGrpc,
    'SKIP_STRESS' => $skipStress,
    'VERBOSE' => $isVerbose ? '1' : (getenv('VERBOSE') ?: '0'),
    'RUN_UNSAFE_TESTS' => '0',
]);

// Run test-local.php from the scripts directory
$scriptPath = __DIR__ . '/test-local.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║           SPOOLED PHP SDK - PRODUCTION TESTS               ║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";
echo '║  Base URL: ' . str_pad($baseUrl, 46) . " ║\n";
echo '║  gRPC:     ' . str_pad($grpcAddress, 46) . " ║\n";
echo '║  Mode:     ' . str_pad($isFull ? 'Full (with stress tests)' : 'Safe (no stress tests)', 46) . " ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// The child writes straight to this process's stdout/stderr. Relaying its
// output through pipes with stream_select() never returned data on Windows
// (select does not work on proc_open pipes there), so the run printed nothing.
$descriptorSpec = [
    0 => ['pipe', 'r'], // stdin (closed right away)
    1 => STDOUT,
    2 => STDERR,
];

$process = proc_open(
    [PHP_BINARY, $scriptPath],
    $descriptorSpec,
    $pipes,
    dirname(__DIR__), // Working directory
    $env,
);

if (!is_resource($process)) {
    fwrite(STDERR, "❌ Failed to start test-local.php\n");
    exit(1);
}

// Close stdin; proc_close() waits for the child and returns its exit code.
fclose($pipes[0]);

$exitCode = proc_close($process);
exit($exitCode);
