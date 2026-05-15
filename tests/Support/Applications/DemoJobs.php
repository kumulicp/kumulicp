<?php

namespace Tests\Support\Applications;

use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;

/**
 * No-op jobs class for DemoApp. DemoApp doesn't perform any real K8s jobs,
 * so all job methods are stubs that return null.
 */
class DemoJobs extends JobChart
{
    public function updateDomain(): void {}

    public function activate(): void {}

    public function deactivate(): void {}
}
