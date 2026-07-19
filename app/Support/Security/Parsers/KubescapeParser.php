<?php

namespace App\Support\Security\Parsers;

use App\Support\Security\Parsers\Concerns\ExtractsJsonReport;

class KubescapeParser implements Parser
{
    use ExtractsJsonReport;

    public function parse(string $raw_output): array
    {
        $data = $this->extractJsonReport($raw_output);

        if (! $data) {
            return [];
        }

        // Per-result controls only carry controlID/name/status/severity - the
        // category grouping lives in the separate control catalog, keyed by
        // controlID. Kubescape's JSON report doesn't include description or
        // remediation text at all (unlike its SARIF output), so we point at
        // the docs page for that control instead of leaving it blank.
        $catalog = $data['summaryDetails']['controls'] ?? [];
        $findings = [];

        foreach ($data['results'] ?? [] as $result) {
            [$resource_type, $resource_name] = $this->parseResourceId($result['resourceID'] ?? null);

            foreach ($result['controls'] ?? [] as $control) {
                if (($control['status']['status'] ?? '') !== 'failed') {
                    continue;
                }

                $control_id = $control['controlID'] ?? null;
                $catalog_entry = $catalog[$control_id] ?? [];

                $findings[] = [
                    'severity' => $this->mapSeverity($control['severity'] ?? $catalog_entry['severity'] ?? null),
                    'title' => $control['name'] ?? 'Kubescape control failed',
                    'category' => $catalog_entry['category']['name'] ?? null,
                    'resource_type' => $resource_type,
                    'resource_name' => $resource_name,
                    'description' => null,
                    'remediation' => $control_id
                        ? 'See https://kubescape.io/docs/controls/'.strtolower($control_id).' for remediation guidance.'
                        : null,
                    'rule_id' => $control_id,
                    'metadata' => null,
                ];
            }
        }

        return $findings;
    }

    /**
     * resourceID is `{apiGroup}/{apiVersion}/{namespace}/{Kind}/{name}`
     * (apiGroup empty for core resources, namespace empty for cluster-scoped
     * ones) - except for access-control findings, where it's several of
     * those tuples concatenated together (subject/role/rolebinding chains).
     * Read from the end rather than assuming a fixed segment count, and only
     * trust the namespace slot if it actually looks like a namespace (lower-
     * case, unlike the "User"/"ServiceAccount"/etc that show up there in the
     * concatenated case).
     *
     * @return array{0: ?string, 1: ?string} [resource_type, resource_name]
     */
    private function parseResourceId(?string $resource_id): array
    {
        if (! $resource_id) {
            return [null, null];
        }

        $parts = explode('/', $resource_id);
        $name = array_pop($parts);
        $kind = array_pop($parts) ?: null;
        $namespace = (count($parts) >= 3 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $parts[2])) ? $parts[2] : null;

        $resource_name = $namespace ? "{$namespace}/{$name}" : ($name ?: null);

        return [$kind, $resource_name];
    }

    private function mapSeverity(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'info',
        };
    }
}
