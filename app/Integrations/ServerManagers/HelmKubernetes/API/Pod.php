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
}
