<?php

namespace App\Support\Security\Parsers;

use App\Support\Security\Parsers\Concerns\ExtractsJsonReport;

class KubeBenchParser implements Parser
{
    use ExtractsJsonReport;

    public function parse(string $raw_output): array
    {
        $data = $this->extractJsonReport($raw_output);

        if (! $data) {
            return [];
        }

        $findings = [];

        foreach ($data['Controls'] ?? [] as $control) {
            $resource_name = $control['node_type'] ?? null;

            foreach ($control['tests'] ?? [] as $test) {
                foreach ($test['results'] ?? [] as $result) {
                    if (($result['status'] ?? '') !== 'FAIL') {
                        continue;
                    }

                    $findings[] = [
                        'severity' => $this->mapSeverity($result['scored'] ?? true),
                        'title' => $result['test_desc'] ?? 'CIS benchmark check failed',
                        'category' => $control['text'] ?? null,
                        'resource_type' => $resource_name ? 'Node' : null,
                        'resource_name' => $resource_name,
                        'description' => $result['audit'] ?? null,
                        'remediation' => $result['remediation'] ?? null,
                        'rule_id' => $result['test_number'] ?? null,
                        'metadata' => $this->filterMetadata([
                            'expected_result' => $result['expected_result'] ?? null,
                            'actual_value' => $result['actual_value'] ?? null,
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

    private function mapSeverity(bool $scored): string
    {
        return $scored ? 'high' : 'medium';
    }
}
