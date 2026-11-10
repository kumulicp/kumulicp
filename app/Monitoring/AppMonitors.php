<?php

namespace App\Monitoring;

use App\AppInstance;
use App\Services\AppInstance\AvailabilityService;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

/**
 * Keeps one uptime monitor (spatie/laravel-uptime-monitor) per app instance,
 * pointed at the app's address:
 *
 *  - created when the app becomes active, with the certificate check on for
 *    https addresses;
 *  - checking only while the app is active or unavailable, so an app that is
 *    activating, updating or being removed doesn't register as down;
 *  - moved when the app's address changes. It is removed with the app by a
 *    cascading foreign key on monitors.app_instance_id, not by this class.
 *
 * The monitor does the HTTP checks; AvailabilityService decides what they mean
 * for the app. A monitor's URL is unique, so an app that lives at its parent's
 * address (see AppInstance::domain()) has no monitor of its own and uses its
 * parent's.
 */
class AppMonitors
{
    /** App statuses in which the monitor checks the app */
    public const MONITORED_STATUSES = ['active', AppInstance::STATUS_UNAVAILABLE];

    /**
     * The monitor that watches the app's address, if there is one.
     */
    public function monitorFor(AppInstance $app): ?AppMonitor
    {
        $monitor = AppMonitor::where('app_instance_id', $app->id)->first();

        if ($monitor === null && $this->sharesParentAddress($app)) {
            $monitor = AppMonitor::where('app_instance_id', $app->parent_id)->first();
        }

        return $monitor;
    }

    /**
     * Brings the app's monitor in line with the app: creates it, moves it,
     * switches it on or off. Does nothing for an app that shouldn't have one.
     */
    public function sync(AppInstance $app): ?AppMonitor
    {
        $monitor = AppMonitor::where('app_instance_id', $app->id)->first();

        if ($this->sharesParentAddress($app) || ! $app->organization) {
            $monitor?->delete();

            return null;
        }

        $monitored = in_array($app->status, self::MONITORED_STATUSES, true);

        if ($monitor === null && ! $monitored) {
            return null;
        }

        $url = $app->address();

        // Another monitor already has this address (one added by hand, or a
        // stray); leave it alone rather than fail on the unique URL
        $holder = AppMonitor::where('url', $url)->first();

        if ($holder !== null && (int) $holder->app_instance_id !== (int) $app->id) {
            return $monitor;
        }

        if ($monitor === null) {
            return AppMonitor::create([
                'url' => $url,
                'app_instance_id' => $app->id,
                'uptime_check_enabled' => true,
                'uptime_check_interval_in_minutes' => AvailabilityService::RECHECK_MINUTES,
                'certificate_check_enabled' => str_starts_with($url, 'https://'),
            ]);
        }

        if ((string) $monitor->url !== $url) {
            $monitor->url = $url;
            $this->forgetResults($monitor);
        }

        if ($monitored && ! $monitor->uptime_check_enabled) {
            $this->forgetResults($monitor);
            $monitor->uptime_check_enabled = true;
            $monitor->certificate_check_enabled = str_starts_with($url, 'https://');
        } elseif (! $monitored && $monitor->uptime_check_enabled) {
            $monitor->uptime_check_enabled = false;
            $monitor->certificate_check_enabled = false;
        }

        $monitor->save();

        return $monitor;
    }

    /**
     * Same, but a problem with a monitor must not fail whatever changed the
     * app (activation, a domain change, ...): it is reported, and the daily
     * reconcile() tries again.
     */
    public function syncQuietly(AppInstance $app): void
    {
        try {
            $this->sync($app);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Syncs every app instance. Also how monitors are created for the apps that
     * were there first.
     *
     * @return int How many app instances were synced
     */
    public function reconcile(): int
    {
        $count = 0;

        AppInstance::with(['organization', 'parent', 'application', 'primary_domain'])
            ->chunkById(100, function ($apps) use (&$count) {
                foreach ($apps as $app) {
                    $this->syncQuietly($app);
                    $count++;
                }
            });

        return $count;
    }

    // Mirrors AppInstance::domain(): a child that lives under its parent's domain
    private function sharesParentAddress(AppInstance $app): bool
    {
        return $app->parent_id !== null && (bool) $app->application?->hasDomainOption('parent');
    }

    // The results belong to the address and to the time the monitor was checking
    private function forgetResults(AppMonitor $monitor): void
    {
        $monitor->uptime_status = UptimeStatus::NOT_YET_CHECKED;
        $monitor->uptime_check_failure_reason = null;
        $monitor->uptime_check_times_failed_in_a_row = 0;
        $monitor->uptime_last_check_date = null;
        $monitor->uptime_check_failed_event_fired_on_date = null;
        $monitor->certificate_status = CertificateStatus::NOT_YET_CHECKED;
    }
}
