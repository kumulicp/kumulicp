<?php

namespace App\Support\Security\Parsers;

class KubescapeParser implements Parser
{
    public function parse(string $raw_output): array
    {
        $data = json_decode($raw_output, true) ?? [];
        $findings = [];

        foreach ($data['results'] ?? [] as $result) {
            foreach ($result['controls'] ?? [] as $control) {
                if (($control['status']['status'] ?? '') !== 'failed') {
                    continue;
                }

                $findings[] = [
                    'severity' => $this->mapSeverity($control['scoreFactor'] ?? 0),
                    'title' => $control['name'] ?? 'Kubescape control failed',
                    'category' => $control['category'] ?? null,
                    'description' => $control['description'] ?? null,
                    'remediation' => $control['remediation'] ?? null,
                    'rule_id' => $control['controlID'] ?? null,
                ];
            }
        }

        return $findings;
    }

    private function mapSeverity(float $score_factor): string
    {
        return match (true) {
            $score_factor >= 8 => 'critical',
            $score_factor >= 6 => 'high',
            $score_factor >= 3 => 'medium',
            $score_factor > 0 => 'low',
            default => 'info',
        };
    }
}
