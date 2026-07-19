<?php

namespace App\Support\Security\Parsers;

use App\Support\Security\Parsers\Concerns\ExtractsJsonReport;

class TrivyParser implements Parser
{
    use ExtractsJsonReport;

    public function parse(string $raw_output): array
    {
        $data = $this->extractJsonReport($raw_output);

        if (! $data) {
            return [];
        }

        // A truncated capture (large report + Kubernetes log rotation) can
        // leave us with just the last complete Resource object rather than
        // the full report envelope - still walk it rather than finding
        // nothing.
        $resources = $data['Resources'] ?? (isset($data['Results']) ? [$data] : []);

        $findings = [];

        foreach ($resources as $resource) {
            $resource_type = $resource['Kind'] ?? null;
            $resource_name = trim(($resource['Namespace'] ?? '').'/'.($resource['Name'] ?? ''), '/') ?: null;

            foreach ($resource['Results'] ?? [] as $result) {
                foreach ($result['Vulnerabilities'] ?? [] as $vuln) {
                    $findings[] = [
                        'severity' => strtolower($vuln['Severity'] ?? 'medium'),
                        'title' => $vuln['Title'] ?? ($vuln['VulnerabilityID'] ?? 'Vulnerability detected'),
                        'category' => 'vulnerability',
                        'resource_type' => $resource_type,
                        'resource_name' => $resource_name,
                        'description' => $vuln['Description'] ?? null,
                        'remediation' => ! empty($vuln['FixedVersion']) ? 'Upgrade '.($vuln['PkgName'] ?? 'the package')." to {$vuln['FixedVersion']}" : null,
                        'rule_id' => $vuln['VulnerabilityID'] ?? null,
                        'metadata' => $this->filterMetadata([
                            'package' => $vuln['PkgName'] ?? null,
                            'installed_version' => $vuln['InstalledVersion'] ?? null,
                            'fixed_version' => $vuln['FixedVersion'] ?? null,
                            'primary_url' => $vuln['PrimaryURL'] ?? null,
                        ]),
                    ];
                }

                foreach ($result['Misconfigurations'] ?? [] as $misconfig) {
                    $findings[] = [
                        'severity' => strtolower($misconfig['Severity'] ?? 'medium'),
                        'title' => $misconfig['Title'] ?? 'Misconfiguration detected',
                        'category' => 'misconfiguration',
                        'resource_type' => $resource_type,
                        'resource_name' => $resource_name,
                        // Message names the exact container/field at fault
                        // (e.g. "Container 'wordpress' of Deployment 'x'
                        // should set..."), which Description doesn't.
                        'description' => $misconfig['Message'] ?? $misconfig['Description'] ?? null,
                        'remediation' => $misconfig['Resolution'] ?? null,
                        'rule_id' => $misconfig['ID'] ?? null,
                        'metadata' => $this->filterMetadata([
                            'primary_url' => $misconfig['PrimaryURL'] ?? null,
                        ]),
                    ];
                }
            }
        }

        return $findings;
    }

    private function filterMetadata(array $metadata): ?array
    {
        $metadata = array_filter($metadata, fn ($value) => $value !== null && $value !== '');

        return $metadata ?: null;
    }
}
