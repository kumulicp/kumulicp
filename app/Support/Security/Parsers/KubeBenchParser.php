<?php

namespace App\Support\Security\Parsers;

class KubeBenchParser implements Parser
{
    public function parse(string $raw_output): array
    {
        $data = json_decode($raw_output, true) ?? [];
        $findings = [];

        foreach ($data['Controls'] ?? [] as $control) {
            foreach ($control['tests'] ?? [] as $test) {
                foreach ($test['results'] ?? [] as $result) {
                    if (($result['status'] ?? '') !== 'FAIL') {
                        continue;
                    }

                    $findings[] = [
                        'severity' => $this->mapSeverity($result['scored'] ?? true),
                        'title' => $result['test_desc'] ?? 'CIS benchmark check failed',
                        'category' => $control['text'] ?? null,
                        'description' => $result['audit'] ?? null,
                        'remediation' => $result['remediation'] ?? null,
                        'rule_id' => $result['test_number'] ?? null,
                    ];
                }
            }
        }

        return $findings;
    }

    private function mapSeverity(bool $scored): string
    {
        return $scored ? 'high' : 'medium';
    }
}
