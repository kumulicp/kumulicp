<?php

namespace App\Support\Security\Parsers;

class TrivyParser implements Parser
{
    public function parse(string $raw_output): array
    {
        $data = json_decode($raw_output, true) ?? [];
        $findings = [];

        foreach ($data['Resources'] ?? [] as $resource) {
            foreach ($resource['Results'] ?? [] as $result) {
                foreach ($result['Vulnerabilities'] ?? [] as $vuln) {
                    $findings[] = [
                        'severity' => strtolower($vuln['Severity'] ?? 'medium'),
                        'title' => $vuln['Title'] ?? ($vuln['VulnerabilityID'] ?? 'Vulnerability detected'),
                        'category' => 'vulnerability',
                        'description' => $vuln['Description'] ?? null,
                        'remediation' => $vuln['FixedVersion'] ? "Upgrade to {$vuln['FixedVersion']}" : null,
                        'rule_id' => $vuln['VulnerabilityID'] ?? null,
                    ];
                }

                foreach ($result['Misconfigurations'] ?? [] as $misconfig) {
                    $findings[] = [
                        'severity' => strtolower($misconfig['Severity'] ?? 'medium'),
                        'title' => $misconfig['Title'] ?? 'Misconfiguration detected',
                        'category' => 'misconfiguration',
                        'description' => $misconfig['Description'] ?? null,
                        'remediation' => $misconfig['Resolution'] ?? null,
                        'rule_id' => $misconfig['ID'] ?? null,
                    ];
                }
            }
        }

        return $findings;
    }
}
