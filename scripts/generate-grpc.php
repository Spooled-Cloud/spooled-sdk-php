#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script to generate PHP gRPC stubs from the proto file.
 *
 * Prerequisites:
 * - protoc (Protocol Buffers compiler)
 * - grpc_php_plugin
 * - protobuf PHP extension
 *
 * Usage:
 *   php scripts/generate-grpc.php
 */
$projectRoot = dirname(__DIR__);
$protoFile = dirname($projectRoot) . '/spooled-backend/proto/spooled.proto';
$outputDir = $projectRoot . '/src/Grpc/Stubs';

// Check prerequisites
function checkPrerequisites(): void
{
    // Check protoc
    exec('which protoc 2>/dev/null', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Error: protoc not found. Install Protocol Buffers compiler.\n");
        fwrite(STDERR, "  macOS: brew install protobuf\n");
        fwrite(STDERR, "  Linux: apt-get install protobuf-compiler\n");
        exit(1);
    }

    // Check grpc_php_plugin
    exec('which grpc_php_plugin 2>/dev/null', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Error: grpc_php_plugin not found. Install gRPC tools.\n");
        fwrite(STDERR, "  See: https://grpc.io/docs/languages/php/quickstart/\n");
        exit(1);
    }
}

function main(): void
{
    global $projectRoot, $protoFile, $outputDir;

    echo "Generating PHP gRPC stubs...\n";

    // Check prerequisites
    checkPrerequisites();

    // Check proto file exists
    if (!file_exists($protoFile)) {
        fwrite(STDERR, "Error: Proto file not found: {$protoFile}\n");
        exit(1);
    }

    // Create output directory
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0o755, true);
    }

    // Generate PHP files
    $protoDir = dirname($protoFile);
    $cmd = sprintf(
        'protoc --proto_path=%s ' .
        '--php_out=%s ' .
        '--grpc_out=%s ' .
        '--plugin=protoc-gen-grpc=%s ' .
        '%s 2>&1',
        escapeshellarg($protoDir),
        escapeshellarg($outputDir),
        escapeshellarg($outputDir),
        trim(shell_exec('which grpc_php_plugin') ?: ''),
        escapeshellarg($protoFile),
    );

    echo "Running: {$cmd}\n";
    exec($cmd, $output, $code);

    if ($code !== 0) {
        fwrite(STDERR, "Error generating stubs:\n");
        fwrite(STDERR, implode("\n", $output) . "\n");
        exit(1);
    }

    // Fix namespace in generated files
    fixNamespaces($outputDir);

    echo "Generated stubs in: {$outputDir}\n";
    echo "Done!\n";
}

function fixNamespaces(string $dir): void
{
    $files = glob($dir . '/*.php');

    foreach ($files as $file) {
        // Skip GPBMetadata files - they need to stay in their original namespace
        if (strpos($file, 'GPBMetadata') !== false) {
            continue;
        }

        $content = file_get_contents($file);

        // Change Spooled\V1 namespace to Spooled\Grpc\Stubs
        $content = preg_replace(
            '/^namespace\s+Spooled\\\\V1;/m',
            'namespace Spooled\\Grpc\\Stubs;',
            $content,
        );

        file_put_contents($file, $content);
    }

    // Process subdirectories (except GPBMetadata)
    foreach (glob($dir . '/*', GLOB_ONLYDIR) as $subdir) {
        if (strpos($subdir, 'GPBMetadata') !== false) {
            continue;
        }
        fixNamespaces($subdir);
    }
}

main();
