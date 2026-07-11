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
}
