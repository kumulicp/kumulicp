<?php

namespace App\Support\Security\Parsers;

class PolarisParser implements Parser
{
    public function parse(string $raw_output): array
    {
        $data = json_decode($raw_output, true) ?? [];
        $findings = [];

        foreach ($data['Results'] ?? [] as $result) {
            foreach ($result['Results'] ?? [] as $category => $checks) {
                foreach ($checks ?? [] as $check_name => $check) {
                    if (($check['Success'] ?? true) === true) {
                        continue;
                    }

                    $findings[] = [
                        'severity' => $this->mapSeverity($check['Severity'] ?? 'warning'),
                        'title' => $check_name,
                        'category' => $category,
                        'description' => $check['Message'] ?? null,
                        'remediation' => null,
                        'rule_id' => $check_name,
                    ];
                }
            }
        }

        return $findings;
    }

    private function mapSeverity(string $severity): string
    {
        return match (strtolower($severity)) {
            'error', 'danger' => 'high',
            'warning' => 'medium',
            default => 'low',
        };
    }
}
