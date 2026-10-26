<?php

namespace App\Support\VersionUpdates;

use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Server;
use Composer\Semver\Semver;
use Throwable;

/**
 * The Kubernetes version of a helm_k8s cluster, and whether a chart's
 * kubeVersion constraint accepts it.
 */
class ClusterVersion
{
    /** @var array<int, string|null> */
    private array $versions = [];

    public function forServer(Server $server): ?string
    {
        if (! array_key_exists($server->id, $this->versions)) {
            $this->versions[$server->id] = $this->lookup($server);
        }

        return $this->versions[$server->id];
    }

    /**
     * True or false when the constraint can be evaluated, null when it
     * can't be parsed. Helm constraints often use "-0" to admit
     * pre-release cluster versions (">=1.25.0-0"), which composer/semver
     * doesn't need since cluster versions are normalized first.
     */
    public function satisfies(string $cluster_version, string $constraint): ?bool
    {
        $constraint = preg_replace('/(\d)-0\b/', '$1', $constraint);

        try {
            return Semver::satisfies($cluster_version, $constraint);
        } catch (Throwable) {
            return null;
        }
    }

    // "v1.31.4+k3s1" -> "1.31.4"
    public static function normalize(string $git_version): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+)/', $git_version, $matches) ? $matches[1] : null;
    }

    private function lookup(Server $server): ?string
    {
        try {
            $result = (new KubernetesApiClient($server))->version();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $result['success']) {
            return null;
        }

        $git_version = $result['data']['gitVersion'] ?? '';

        return self::normalize($git_version);
    }
}
