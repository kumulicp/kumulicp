<?php

namespace App\Support\Security\Parsers;

use App\Support\Security\Parsers\Concerns\ExtractsJsonReport;

class PolarisParser implements Parser
{
    use ExtractsJsonReport;

    public function parse(string $raw_output): array
    {
        $data = $this->extractJsonReport($raw_output);

        if (! $data) {
            return [];
        }

        $findings = [];

        foreach ($data['Results'] ?? [] as $result) {
            $resource_type = $result['Kind'] ?? null;
            $resource_name = trim(($result['Namespace'] ?? '').'/'.($result['Name'] ?? ''), '/') ?: null;

            $findings = array_merge($findings, $this->collectChecks($result['Results'] ?? [], $resource_type, $resource_name));

            $pod_result = $result['PodResult'] ?? null;

            if (! $pod_result) {
                continue;
            }

            $findings = array_merge($findings, $this->collectChecks($pod_result['Results'] ?? [], $resource_type, $resource_name));

            foreach ($pod_result['ContainerResults'] ?? [] as $container) {
                $findings = array_merge($findings, $this->collectChecks($container['Results'] ?? [], $resource_type, $resource_name, $container['Name'] ?? null));
            }
        }

        return $findings;
    }

    /**
     * Polaris' `Results` maps are always a flat check-name => check dict
     * (not grouped by category - each check carries its own `Category`), at
     * three separate levels: the controller itself, the pod template, and
     * each container. $container identifies which container this particular
     * map of checks came from, for checks found at the container level.
     */
    private function collectChecks(array $checks, ?string $resource_type, ?string $resource_name, ?string $container = null): array
    {
        $findings = [];

        foreach ($checks as $check_name => $check) {
            if (($check['Success'] ?? true) === true) {
                continue;
            }

            $findings[] = [
                'severity' => $this->mapSeverity($check['Severity'] ?? 'warning'),
                'title' => $check['Message'] ?? $check_name,
                'category' => $check['Category'] ?? null,
                'resource_type' => $resource_type,
                'resource_name' => $resource_name,
                'description' => null,
                'remediation' => $this->mutationSummary($check['Mutations'] ?? null),
                'rule_id' => $check['ID'] ?? $check_name,
                'metadata' => $container ? ['container' => $container] : null,
            ];
        }

        return $findings;
    }

    private function mutationSummary(?array $mutations): ?string
    {
        if (empty($mutations)) {
            return null;
        }

        return collect($mutations)
            ->map(fn ($mutation) => trim("Set {$mutation['Path']} to ".json_encode($mutation['Value'] ?? null)))
            ->implode('; ');
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
