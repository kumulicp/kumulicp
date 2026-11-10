<?php

namespace App\Monitoring;

use App\AppInstance;
use App\Services\AppInstance\AvailabilityService;

/**
 * Keeps the app's uptime monitor in step with the app: switched on when the
 * app becomes active (and created the first time), off when it stops being
 * active, moved when its address changes. It also ends an outage when the app
 * is active again after being unavailable (see AvailabilityService::settle()).
 * Removing the monitor with the app is the database's job (a cascading foreign
 * key on monitors.app_instance_id), so it holds however the app is deleted.
 */
class AppInstanceObserver
{
    // What AppInstance::address() and AppMonitors::sync() depend on
    private const WATCHED = ['status', 'primary_domain_id', 'parent_id', 'name', 'organization_id'];

    public function __construct(private AppMonitors $monitors) {}

    public function saved(AppInstance $app): void
    {
        if ($app->wasRecentlyCreated || $app->wasChanged(self::WATCHED)) {
            $this->monitors->syncQuietly($app);
        }

        // Active again after having been unavailable (an upgrade fixed it):
        // that is an outage that ended, which is told and forgotten
        if ($app->wasChanged('status') && $app->status === 'active') {
            try {
                app(AvailabilityService::class)->settle($app);
            } catch (\Throwable $e) {
                // Must not fail whatever made the app active
                report($e);
            }
        }
    }
}
