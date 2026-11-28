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

    // Whether the chart was detected to keep a volume (see ChartConventionDetector)
    private bool $has_volume = false;

    /**
     * @param  AppChart|null  $chart  a catalog chart added to an app that is deployed another way, whose own data is used instead of the app's
     */
    public function __construct(Application $application, ?AppChart $chart = null)
    {
        $this->name = $application->slug;
        $this->configurations = ($chart->catalog ?? $application->catalog)['configurations'] ?? [];
        $this->has_volume = (bool) (($chart->catalog ?? $application->catalog)['convention']['persistence'] ?? null);
    }

    /**
     * The volume the chart was detected to have, if it was.
     */
    public function volumes(): array
    {
        return $this->has_volume ? parent::volumes() : [];
    }

    public function storageHandover(AppInstance $app_instance): ?array
    {
        // Its chart is never given an access mode
        $handover = parent::storageHandover($app_instance);

        unset($handover['access_mode']);

        return $handover;
    }
}
