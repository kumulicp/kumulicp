<?php

namespace App\Support\Security\Parsers;

use App\Support\Security\Parsers\Concerns\ExtractsJsonReport;

class KubeHunterParser implements Parser
{
    use ExtractsJsonReport;

    public function parse(string $raw_output): array
    {
        $data = $this->extractJsonReport($raw_output);

        if (! $data) {
            return [];
        }

        $findings = [];

        foreach ($data['vulnerabilities'] ?? [] as $vuln) {
            $findings[] = [
                'severity' => $this->mapSeverity($vuln['severity'] ?? 'medium'),
                'title' => $vuln['vulnerability'] ?? 'Unknown vulnerability',
                'category' => $vuln['category'] ?? null,
                'resource_type' => null,
                'resource_name' => $vuln['location'] ?? null,
                'description' => $vuln['description'] ?? null,
                // kube-hunter doesn't provide remediation text - "evidence" is
                // proof of the finding (sometimes a raw token/file list), not
                // guidance, so it belongs in metadata rather than here.
                'remediation' => ! empty($vuln['avd_reference'])
                    ? "See {$vuln['avd_reference']} for remediation guidance."
                    : null,
                'rule_id' => $this->normalizeVid($vuln['vid'] ?? null),
                'metadata' => $this->filterMetadata([
                    'hunter' => $vuln['hunter'] ?? null,
                    'evidence' => $vuln['evidence'] ?? null,
                ]),
            ];
        }

        return $findings;
    }

    /**
     * kube-hunter serializes Python's `None` as the literal string "None"
     * (not JSON null) when a vulnerability has no assigned ID.
     */
    private function normalizeVid(?string $vid): ?string
    {
        return ($vid && $vid !== 'None') ? $vid : null;
    }

    private function filterMetadata(array $metadata): ?array
    {
        $metadata = array_filter($metadata, fn ($value) => $value !== null && $value !== '');

        return $metadata ?: null;
    }

    private function mapSeverity(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'info',
        };
    }
}
