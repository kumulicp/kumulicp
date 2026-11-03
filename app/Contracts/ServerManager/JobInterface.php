<?php

namespace App\Contracts\ServerManager;

use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;

/**
 * Running one-off Jobs on a web server. $namespace is where the Job runs when
 * that isn't the organization's own namespace.
 */
interface JobInterface
{
    /**
     * @return array{status: string, response: mixed} status is 'success' or 'failed';
     *                                                the created Job's object is in response on success
     */
    public function runJob(JobChart $job_chart, ?string $namespace = null);

    /**
     * @return string 'running', 'success' or 'failed'
     */
    public function jobStatus(string $job_id, ?string $namespace = null);

    /**
     * The Job's log output, or null if it has none (yet).
     */
    public function jobLogs(string $job_id, ?string $namespace = null): ?string;
}
