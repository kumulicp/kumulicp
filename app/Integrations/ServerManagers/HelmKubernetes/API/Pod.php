<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;

class Pod extends Kubernetes
{
    public function logsForJob(string $job_name): ?string
    {
        $namespace = $this->namespace();

        $result = $this->kubectl()->run(['logs', '-l', "job-name={$job_name}", '--tail=-1'], $namespace);

        if (! $result['success'] || $result['output'] === '') {
            return null;
        }

        return $result['output'];
    }

    // Logs of the most recent install/upgrade/uninstall Job for a release
    // (labelled by HelmInstallJob), or null if there isn't one -- e.g. it was
    // already reaped by ttlSecondsAfterFinished.
    public function latestInstallLogs(string $release_slug): ?string
    {
        $result = $this->kubectl()->run(
            ['get', 'jobs', '-l', "kumulicp.io/release={$release_slug}", '--sort-by=.metadata.creationTimestamp', '-o', 'json'],
            $this->namespace(),
        );

        if (! $result['success']) {
            return null;
        }

        $jobs = json_decode($result['output'], true)['items'] ?? [];
        $name = $jobs === [] ? null : ($jobs[array_key_last($jobs)]['metadata']['name'] ?? null);

        return $name ? $this->logsForJob($name) : null;
    }
}
