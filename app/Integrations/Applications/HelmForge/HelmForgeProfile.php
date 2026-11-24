<?php

namespace App\Integrations\Applications\HelmForge;

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

    // Not `rancher`: Rancher can only install from repos registered in it
    protected $compatibility = ['helm_chart'];

    protected $helm_chart = HelmForgeChart::class;

    public function __construct(Application $application)
    {
        $this->name = $application->slug;
        $this->configurations = $application->catalog['configurations'] ?? [];
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
