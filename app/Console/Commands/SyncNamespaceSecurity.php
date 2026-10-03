<?php

namespace App\Console\Commands;

use App\Integrations\ServerManagers\HelmKubernetes\API\KubernetesNamespace as HelmNamespace;
use App\Integrations\ServerManagers\Rancher\API\KubernetesNamespace as RancherNamespace;
use App\Organization;
use App\OrgServer;
use App\Support\Security\NamespaceSecurityPolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings every managed namespace's Pod Security labels in line with its
 * plan's security tier, for namespaces that existed before a tier was chosen
 * or whose tier definition has since changed. Only servers whose
 * security_mode is `managed` are touched; everything else reports
 * "unmanaged" and no cluster call is made.
 */
class SyncNamespaceSecurity extends Command
{
    protected $signature = 'servers:sync-namespace-security
        {--dry-run : Show what would change without changing anything}
        {--organization= : Only this organization (slug)}
        {--server= : Only this server (id)}';

    protected $description = "Reconcile organization namespaces' Pod Security labels with their plan's security tier";

    public function handle(): int
    {
        $dry_run = (bool) $this->option('dry-run');

        $org_servers = OrgServer::with(['organization.plan', 'server'])
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
        $failed = false;

        foreach ($org_servers as $org_server) {
            $organization = $org_server->organization;
            $server = $org_server->server;

            if (! $organization) {
                continue;
            }

            try {
                $result = $this->namespaceFor($organization, $org_server)->reconcileSecurity(apply: ! $dry_run);
            } catch (Throwable $e) {
                $result = ['status' => 'failed', 'action' => 'error', 'changes' => [], 'response' => $e->getMessage()];
            }

            if ($result['status'] === 'failed') {
                $failed = true;
            }

            $rows[] = [
                $organization->slug,
                $server->name,
                NamespaceSecurityPolicy::mode($server),
                NamespaceSecurityPolicy::tierFor($organization)->key,
                $result['action'],
                $this->describe($result),
            ];
        }

        if ($rows === []) {
            $this->info('No Kubernetes web servers with organizations found.');

            return self::SUCCESS;
        }

        $this->table(['Organization', 'Server', 'Mode', 'Tier', 'Result', 'Details'], $rows);

        if ($dry_run) {
            $this->comment('Dry run: nothing was changed.');
        }

        if ($failed) {
            $this->error("Some namespaces couldn't be updated. If the error mentions \"forbidden\", the credentials need permission to patch/update namespaces (see docs/k8s-rbac-sample.yaml).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function namespaceFor(Organization $organization, OrgServer $org_server): HelmNamespace|RancherNamespace
    {
        return $org_server->server->interface === 'rancher'
            ? new RancherNamespace($organization, $org_server)
            : new HelmNamespace($organization, $org_server);
    }

    private function describe(array $result): string
    {
        if (isset($result['response'])) {
            return $result['response'];
        }

        $parts = [];
        foreach (['labels', 'annotations'] as $kind) {
            foreach ($result['changes'][$kind] ?? [] as $key => $value) {
                $parts[] = $value === null ? "-{$key}" : "{$key}={$value}";
            }
        }

        return implode(', ', $parts);
    }
}
