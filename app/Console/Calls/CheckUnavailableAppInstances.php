<?php

namespace App\Console\Calls;

use App\AppInstance;
use App\Monitoring\AppMonitors;
use App\Services\AppInstance\AvailabilityService;

/**
 * Reads what the uptime monitors (monitor:check-uptime, which makes the HTTP
 * checks every RECHECK_MINUTES) found about the apps marked unavailable, and
 * acts on it: back to normal, or blocked. No HTTP of its own, so it is cheap to
 * run every minute.
 */
class CheckUnavailableAppInstances
{
    public function __invoke(): void
    {
        $service = app(AvailabilityService::class);
        $monitors = app(AppMonitors::class);

        foreach (AppInstance::unavailable()->with(['organization', 'parent', 'application'])->get() as $app) {
            try {
                // Normally there already is one; this covers an app that was
                // marked before its monitor was created
                $monitor = $monitors->monitorFor($app) ?? $monitors->sync($app);

                if ($monitor !== null) {
                    $service->evaluate($app, $monitor);
                }
            } catch (\Throwable $e) {
                // One app that can't be checked mustn't stop the rest
                report($e);
            }
        }
    }
}
