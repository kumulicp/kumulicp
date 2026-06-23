<?php

namespace App\Support\Security\Parsers;

class KubeHunterParser implements Parser
{
    public function parse(string $raw_output): array
    {
        $data = json_decode($raw_output, true) ?? [];
        $findings = [];

        foreach ($data['vulnerabilities'] ?? [] as $vuln) {
            $findings[] = [
                'severity' => $this->mapSeverity($vuln['severity'] ?? 'medium'),
                'title' => $vuln['vulnerability'] ?? 'Unknown vulnerability',
                'category' => $vuln['category'] ?? null,
                'description' => $vuln['description'] ?? null,
                'remediation' => $vuln['evidence'] ?? null,
                'rule_id' => $vuln['vid'] ?? null,
            ];
        }

        return $findings;
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
