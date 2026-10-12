#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script to generate PHP gRPC stubs from the proto file.
 *
 * The source of truth is the backend proto (`../spooled-backend/proto/spooled.proto`
 * next to this repository, or the path in SPOOLED_PROTO). The generated classes
 * keep the `Spooled\V1` namespace that composer.json autoloads from
 * `src/Grpc/Stubs/Spooled/V1/`.
 *
 * Prerequisites:
 * - protoc (Protocol Buffers compiler); PROTOC overrides the binary
 * - grpc_php_plugin for the service clients (QueueServiceClient,
 *   WorkerServiceClient); GRPC_PHP_PLUGIN overrides the binary
 *
 * Usage:
 *   php scripts/generate-grpc.php
 *   php scripts/generate-grpc.php --messages-only
 *
 * --messages-only regenerates the message classes and GPBMetadata only and
 * keeps the checked-in service clients. Use it when the proto changed messages
 * but no RPCs (the service clients depend only on the RPC list) and
 * grpc_php_plugin is not installed.
 */
$projectRoot = dirname(__DIR__);
$protoFile = getenv('SPOOLED_PROTO') ?: dirname($projectRoot) . '/spooled-backend/proto/spooled.proto';
$outputDir = $projectRoot . '/src/Grpc/Stubs';
$messagesOnly = in_array('--messages-only', array_slice($argv, 1), true);

/**
 * Locate an executable: an explicit override, else a PATH lookup that works on
 * Linux, macOS and Windows.
 */
function findExecutable(string $name, string $overrideEnv): ?string
{
    $override = getenv($overrideEnv);
    if (is_string($override) && $override !== '') {
        return $override;
    }

    $lookup = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
    $output = [];
    exec($lookup . ' ' . escapeshellarg($name) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $output, $code);

    return $code === 0 && isset($output[0]) && trim($output[0]) !== '' ? trim($output[0]) : null;
}

function main(string $protoFile, string $outputDir, bool $messagesOnly): void
{
    echo "Generating PHP gRPC stubs...\n";

    $protoc = findExecutable('protoc', 'PROTOC');
    if ($protoc === null) {
        fwrite(STDERR, "Error: protoc not found. Install Protocol Buffers compiler or set PROTOC.\n");
        fwrite(STDERR, "  macOS: brew install protobuf\n");
        fwrite(STDERR, "  Linux: apt-get install protobuf-compiler\n");
        exit(1);
    }

    $plugin = $messagesOnly ? null : findExecutable('grpc_php_plugin', 'GRPC_PHP_PLUGIN');
    if (!$messagesOnly && $plugin === null) {
        fwrite(STDERR, "Error: grpc_php_plugin not found. Install gRPC tools or set GRPC_PHP_PLUGIN.\n");
        fwrite(STDERR, "  See: https://grpc.io/docs/languages/php/quickstart/\n");
        fwrite(STDERR, "  If no RPC changed, --messages-only keeps the checked-in service clients.\n");
        exit(1);
    }

    if (!file_exists($protoFile)) {
        fwrite(STDERR, "Error: Proto file not found: {$protoFile}\n");
        exit(1);
    }

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0o755, true);
    }

    $args = [
        escapeshellarg($protoc),
        '--proto_path=' . escapeshellarg(dirname($protoFile)),
        '--php_out=' . escapeshellarg($outputDir),
    ];
    if ($plugin !== null) {
        $args[] = '--grpc_out=' . escapeshellarg($outputDir);
        $args[] = '--plugin=protoc-gen-grpc=' . escapeshellarg($plugin);
    }
    $args[] = escapeshellarg($protoFile);
    $cmd = implode(' ', $args) . ' 2>&1';

    echo "Running: {$cmd}\n";
    exec($cmd, $output, $code);

    if ($code !== 0) {
        fwrite(STDERR, "Error generating stubs:\n");
        fwrite(STDERR, implode("\n", $output) . "\n");
        exit(1);
    }

    // The generated `namespace Spooled\V1;` is what composer.json autoloads;
    // it must not be rewritten.

    $version = [];
    exec(escapeshellarg($protoc) . ' --version', $version);
    echo "Generated stubs in: {$outputDir}\n";
    echo 'protoc: ' . trim($version[0] ?? 'unknown') . "\n";
    echo 'proto sha256: ' . hash_file('sha256', $protoFile) . "\n";
    echo 'service clients: ' . ($plugin !== null ? "regenerated with {$plugin}" : 'kept (--messages-only)') . "\n";
    echo "Done!\n";
}

main($protoFile, $outputDir, $messagesOnly);
