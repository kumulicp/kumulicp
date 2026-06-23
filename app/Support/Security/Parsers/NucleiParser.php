<?php

namespace App\Support\Security\Parsers;

use Illuminate\Support\Str;

class NucleiParser implements Parser
{
    public function parse(string $raw_output): array
    {
        $findings = [];

        foreach (Str::of($raw_output)->explode("\n") as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (! $event) {
                continue;
            }

            $info = $event['info'] ?? [];

            $findings[] = [
                'severity' => strtolower($info['severity'] ?? 'info'),
                'title' => $info['name'] ?? ($event['template-id'] ?? 'Nuclei finding'),
                'category' => implode(',', $info['tags'] ?? []) ?: null,
                'description' => $event['matched-at'] ?? null,
                'remediation' => $info['remediation'] ?? null,
                'rule_id' => $event['template-id'] ?? null,
            ];
        }

        return $findings;
    }
}
