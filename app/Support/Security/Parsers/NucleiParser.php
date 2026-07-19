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

            $description = $info['description'] ?? null;
            if ($matched_at = $event['matched-at'] ?? null) {
                $description = trim("Matched at: {$matched_at}\n\n".($description ?? ''));
            }

            $findings[] = [
                'severity' => strtolower($info['severity'] ?? 'info'),
                'title' => $info['name'] ?? ($event['template-id'] ?? 'Nuclei finding'),
                'category' => implode(',', $info['tags'] ?? []) ?: null,
                'description' => $description,
                'remediation' => $info['remediation'] ?? (! empty($info['reference']) ? 'See: '.implode(', ', (array) $info['reference']) : null),
                'rule_id' => $event['template-id'] ?? null,
            ];
        }

        return $findings;
    }
}
