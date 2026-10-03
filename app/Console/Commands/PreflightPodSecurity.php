<?php

namespace App\Console\Commands;

use App\OrgServer;
use App\Support\Security\PodSecurityPreflight;
use App\Support\Security\SecurityTier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reports which existing workloads would be blocked if a namespace enforced a
 * given Pod Security level, via a server-side dry run: nothing is changed.
 * Each check is recorded as a "pod-security" security scan unless --no-record.
 */
class PreflightPodSecurity extends Command
{
    protected $signature = 'servers:preflight-pod-security
        {level : privileged, baseline or restricted}
        {--organization= : Only this organization (slug)}
        {--server= : Only this server (id)}
        {--pss-version=latest : Pod Security version to check against, e.g. v1.30}
        {--no-record : Don\'t keep the results as security scans}';

    protected $description = 'Show which existing workloads would violate a Pod Security enforce level, without changing anything';

    public function handle(): int
    {
        $level = $this->argument('level');

        if (SecurityTier::level($level) === null) {
            $this->error('Level must be one of: '.implode(', ', SecurityTier::LEVELS));

            return self::INVALID;
        }

        $org_servers = OrgServer::with(['organization', 'server'])
            ->whereHas('server', function ($query) {
                $query->where('type', 'web')->whereIn('interface', ['helm_k8s', 'rancher']);

                if ($this->option('server')) {
                    $query->where('id', $this->option('server'));
                }
            })
            ->when($this->option('organization'), function ($query, $slug) {
                $query->whereHas('organization', fn ($org) => $org->where('slug', $slug));
            })
            ->get();

        $rows = [];
        $problem = false;

        foreach ($org_servers as $org_server) {
            if (! $org_server->organization) {
                continue;
            }

            try {
                $result = PodSecurityPreflight::check($org_server->organization, $org_server, $level, $this->option('pss-version'));
            } catch (Throwable $e) {
                $result = ['status' => 'error', 'error' => $e->getMessage(), 'pods' => [], 'pod_count' => 0, 'level' => $level, 'warnings' => []];
            }

            if (! $this->option('no-record')) {
                PodSecurityPreflight::record($org_server, $result, 'command');
            }

            $problem = $problem || in_array($result['status'], ['violations', 'error'], true);

            $rows[] = [
                $org_server->organization->slug,
                $org_server->server->name,
                $result['status'],
                $result['pod_count'],
                $result['status'] === 'error'
                    ? $result['error']
                    : collect($result['pods'])->map(fn ($pod) => $pod['pod'].($pod['others'] ? " (+{$pod['others']})" : ''))->implode(', '),
            ];
        }

        if ($rows === []) {
            $this->info('No Kubernetes web servers with organizations found.');

            return self::SUCCESS;
        }

        $this->table(['Organization', 'Server', 'Result', 'Pods', 'Details'], $rows);

        if (collect($rows)->contains(fn ($row) => $row[2] === 'unsupported')) {
            $this->comment('"unsupported": only helm_k8s servers can be preflighted; Rancher servers need to be checked another way.');
        }

        return $problem ? self::FAILURE : self::SUCCESS;
    }
}
