<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;

class Pod extends Kubernetes
{
    public function logsForJob(string $job_name): ?string
    {
        $namespace = $this->namespace();

        $result = $this->api()->podLogs("job-name={$job_name}", $namespace);

        if (! $result['success'] || $result['data'] === '') {
            return null;
        }

        return $result['data'];
    }

    // Logs of the most recent install/upgrade/uninstall Job for a release
    // (labelled by HelmInstallJob), or null if there isn't one -- e.g. it was
    // already reaped by ttlSecondsAfterFinished.
    public function latestInstallLogs(string $release_slug): ?string
    {
        $result = $this->api()->list('batch/v1', 'Job', $this->namespace(), "kumulicp.io/release={$release_slug}");

        if (! $result['success']) {
            return null;
        }

        $jobs = $result['data']['items'] ?? [];

        // creationTimestamp is ISO 8601, so it sorts chronologically as text
        usort($jobs, fn ($a, $b) => strcmp($a['metadata']['creationTimestamp'] ?? '', $b['metadata']['creationTimestamp'] ?? ''));

        $name = $jobs === [] ? null : ($jobs[array_key_last($jobs)]['metadata']['name'] ?? null);

        return $name ? $this->logsForJob($name) : null;
    }
}
