<?php

namespace App\Integrations\Applications\HelmForge;

use App\AppChart;
use App\AppInstance;
use App\Application;
use App\Integrations\Applications\AppProfile;

/**
 * The profile of an app imported from a chart source. Unlike the hand-written
 * profiles it has no code of its own: everything comes from the `catalog`
 * data stored on the application when the chart was synced.
 */
class HelmForgeProfile extends AppProfile
{
    protected $activation_type = 'chart';

    // Rancher can only install from a repository registered in it, so on a Rancher server
    // the chart's repository is the name it was given there instead of its URL (see AppChart)
    protected $compatibility = ['helm_chart', 'rancher'];

    protected $helm_chart = HelmForgeChart::class;

    /**
     * @param  AppChart|null  $chart  a catalog chart added to an app that is deployed another way, whose own data is used instead of the app's
     */
    public function __construct(Application $application, ?AppChart $chart = null)
    {
        $this->name = $application->slug;
        $this->configurations = ($chart?->catalog ?? $application->catalog)['configurations'] ?? [];
    }

    public function storageHandover(AppInstance $app_instance): ?array
    {
        // Its chart is never given an access mode
        return [
            'claim' => ['path' => 'override.pvc.name'],
            'storage_class' => ['path' => 'configurations.persistence-storageClass'],
            'size' => ['path' => 'chart_storage'],
        ];
    }
}
