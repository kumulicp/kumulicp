<?php

namespace App\Integrations\Applications;

use App\AppInstance;

/**
 * A change an extension (a module) makes to the chart an app deploys, without
 * the app's own chart knowing about it. Register one on an app's profile with
 * AppProfile::addChartModifier(); every method has to return its input
 * untouched when the change does not apply to the instance.
 */
abstract class ChartModifier
{
    /**
     * Change the values of the app's Helm release.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function values(array $values, AppInstance $app_instance): array
    {
        return $values;
    }

    /**
     * Change a Kubernetes Job the app runs against its release.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public function job(array $job, AppInstance $app_instance): array
    {
        return $job;
    }
}
