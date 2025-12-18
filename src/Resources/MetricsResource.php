<?php

declare(strict_types=1);

namespace Spooled\Resources;

/**
 * Metrics resource for Prometheus metrics.
 */
final class MetricsResource extends BaseResource
{
    /**
     * Get Prometheus metrics.
     */
    public function get(): string
    {
        return $this->httpClient->getRaw('metrics', skipApiPrefix: true);
    }

    /**
     * Parse metrics into array format.
     *
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        $raw = $this->get();
        $metrics = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse metric line
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(\{[^}]*\})?\s+(.+)$/', $line, $matches)) {
                $name = $matches[1];
                $labels = $matches[2] ?? '';
                $value = $matches[3];

                if (!isset($metrics[$name])) {
                    $metrics[$name] = [];
                }

                $metrics[$name][] = [
                    'labels' => $this->parseLabels($labels),
                    'value' => is_numeric($value) ? (float) $value : $value,
                ];
            }
        }

        return $metrics;
    }

    /**
     * Get a specific metric by name.
     *
     * @return array<array{labels: array<string, string>, value: float|string}>|null
     */
    public function getMetric(string $name): ?array
    {
        $metrics = $this->parse();

        return $metrics[$name] ?? null;
    }

    /**
     * Parse Prometheus labels.
     *
     * @return array<string, string>
     */
    private function parseLabels(string $labels): array
    {
        if ($labels === '' || $labels === '{}') {
            return [];
        }

        $result = [];
        $labels = trim($labels, '{}');

        // Simple label parsing (handles most cases)
        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="([^"]*)"/', $labels, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $result[$match[1]] = $match[2];
            }
        }

        return $result;
    }
}
