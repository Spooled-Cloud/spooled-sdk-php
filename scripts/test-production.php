#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Production test runner for scripts/test-local.php.
 *
 * Safe defaults:
 * - Uses Spooled Cloud endpoints
 * - Skips stress/load tests unless --full is provided (to avoid noisy prod runs)
 *
 * Usage:
 *   API_KEY=sk_live_... php scripts/test-production.php
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
    fwrite(STDERR, "   Example: API_KEY=sk_live_... php scripts/test-production.php\n");
    exit(1);
}

// Production defaults
$baseUrl = getenv('BASE_URL') ?: 'https://api.spooled.cloud';
$grpcAddress = getenv('GRPC_ADDRESS') ?: 'grpc.spooled.cloud:443';

// Default to running gRPC tests in production (can be overridden)
$skipGrpc = getenv('SKIP_GRPC') !== false ? getenv('SKIP_GRPC') : '0';
// Default to skipping stress tests in production (can be overridden or --full)
$skipStress = $isFull ? '0' : (getenv('SKIP_STRESS') !== false ? getenv('SKIP_STRESS') : '1');

// Build environment
$env = [
    'API_KEY' => $apiKey,
    'BASE_URL' => $baseUrl,
    'GRPC_ADDRESS' => $grpcAddress,
    'SKIP_GRPC' => $skipGrpc,
    'SKIP_STRESS' => $skipStress,
    'VERBOSE' => $isVerbose ? '1' : (getenv('VERBOSE') ?: '0'),
];

// Preserve existing environment variables
foreach ($_ENV as $key => $value) {
    if (!isset($env[$key])) {
        $env[$key] = $value;
    }
}

// Also include getenv values that might not be in $_ENV
foreach (['PATH', 'HOME', 'USER', 'SHELL', 'TERM'] as $key) {
    $value = getenv($key);
    if ($value !== false && !isset($env[$key])) {
        $env[$key] = $value;
    }
}

// Run test-local.php from the scripts directory
$scriptPath = __DIR__ . '/test-local.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║           SPOOLED PHP SDK - PRODUCTION TESTS               ║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";
echo "║  Base URL: " . str_pad($baseUrl, 46) . " ║\n";
echo "║  gRPC:     " . str_pad($grpcAddress, 46) . " ║\n";
echo "║  Mode:     " . str_pad($isFull ? 'Full (with stress tests)' : 'Safe (no stress tests)', 46) . " ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Create process with environment
$descriptorSpec = [
    0 => ['pipe', 'r'], // stdin
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w'], // stderr
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

// Close stdin
fclose($pipes[0]);

// Stream stdout
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

while (true) {
    $read = [$pipes[1], $pipes[2]];
    $write = null;
    $except = null;
    
    $changed = @stream_select($read, $write, $except, 1);
    
    if ($changed === false) {
        break;
    }
    
    foreach ($read as $pipe) {
        $data = fread($pipe, 8192);
        if ($data !== false && $data !== '') {
            if ($pipe === $pipes[1]) {
                echo $data;
            } else {
                fwrite(STDERR, $data);
            }
        }
    }
    
    // Check if process has finished
    $status = proc_get_status($process);
    if (!$status['running']) {
        // Read any remaining output
        $remaining1 = stream_get_contents($pipes[1]);
        $remaining2 = stream_get_contents($pipes[2]);
        if ($remaining1) {
            echo $remaining1;
        }
        if ($remaining2) {
            fwrite(STDERR, $remaining2);
        }
        break;
    }
}

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);
exit($exitCode);
