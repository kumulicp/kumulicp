<?php

namespace App\Support\Security;

use App\Integrations\ServerManagers\HelmKubernetes\Support\KubernetesApiClient;
use App\Organization;
use App\OrgServer;
use App\SecurityFinding;
use App\SecurityScan;
use InvalidArgumentException;

/**
 * Asks the API server what raising a namespace's Pod Security `enforce` level
 * would do to the workloads already running in it, without changing anything:
 * a server-side dry run of the label change, whose admission warnings list
 * every existing pod that would violate the new level (the REST equivalent of
 * `kubectl label --dry-run=server`).
 *
 * Only the helm_k8s driver is covered. Rancher's namespace API is a different
 * surface and hasn't been verified to return these warnings, so Rancher
 * servers report `unsupported` rather than a guess.
 */
class PodSecurityPreflight
{
    public const TOOL = 'pod-security';

    /**
     * status is one of:
     *   ok           no existing pod would violate the level
     *   violations   some would (see pods)
     *   error        the check itself failed, so nothing is known
     *   unsupported  this server's driver can't be checked
     *
     * @return array{status: string, level: string, pods: list<array{pod: string, others: int, violations: list<string>}>, pod_count: int, headline: ?string, warnings: list<string>, error: string}
     */
    public static function check(Organization $organization, OrgServer $org_server, string $level, string $version = 'latest'): array
    {
        if (SecurityTier::level($level) === null) {
            throw new InvalidArgumentException("Unknown Pod Security level: {$level}");
        }

        $result = ['status' => 'ok', 'level' => $level, 'pods' => [], 'pod_count' => 0, 'headline' => null, 'warnings' => [], 'error' => ''];
        $server = $org_server->server;

        if ($server->interface !== 'helm_k8s') {
            return ['status' => 'unsupported'] + $result;
        }

        $labels = [SecurityTier::LABEL_PREFIX.'enforce' => $level];
        $version = SecurityTier::version($version);

        if ($version !== 'latest') {
            $labels[SecurityTier::LABEL_PREFIX.'enforce-version'] = $version;
        }

        $response = (new KubernetesApiClient($server))->patch(
            'v1', 'Namespace', $organization->slug, ['metadata' => ['labels' => $labels]], dry_run: true,
        );

        // No namespace yet means no workloads to break
        if ($response['status'] === 404) {
            return $result;
        }

        if (! $response['success']) {
            return ['status' => 'error', 'error' => $response['error']] + $result;
        }

        $parsed = PreflightWarnings::parse($response['warnings']);

        return [
            'status' => $parsed['pods'] === [] ? 'ok' : 'violations',
            'pods' => $parsed['pods'],
            'pod_count' => PreflightWarnings::podCount($parsed),
            'headline' => $parsed['headline'],
            'warnings' => $response['warnings'],
        ] + $result;
    }

    /**
     * Keeps the outcome as a SecurityScan, so it shows up with the other
     * security scans. Returns null for results that say nothing (unsupported).
     */
    public static function record(OrgServer $org_server, array $result, string $triggered_by = 'preflight'): ?SecurityScan
    {
        if ($result['status'] === 'unsupported') {
            return null;
        }

        $scan = SecurityScan::create([
            'org_server_id' => $org_server->id,
            'tool' => self::TOOL,
            'status' => $result['status'] === 'error' ? 'failed' : 'complete',
            'triggered_by' => $triggered_by,
            'error_message' => $result['status'] === 'error' ? mb_substr($result['error'], 0, 250) : null,
            'raw_output' => json_encode(['level' => $result['level'], 'warnings' => $result['warnings']]),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        foreach ($result['pods'] as $pod) {
            SecurityFinding::create([
                'security_scan_id' => $scan->id,
                'severity' => 'high',
                'title' => "{$pod['pod']} would be blocked at {$result['level']}",
                'category' => 'pod-security',
                'resource_type' => 'Pod',
                'resource_name' => $pod['pod'],
                'description' => implode("\n", $pod['violations']),
                'remediation' => 'Set the missing securityContext fields on the workload (or its chart values), or choose a lower enforce level.',
                'rule_id' => "psa.enforce.{$result['level']}",
                'metadata' => ['others' => $pod['others'], 'violations' => $pod['violations']],
            ]);
        }

        $scan->summarize();

        return $scan;
    }
}
