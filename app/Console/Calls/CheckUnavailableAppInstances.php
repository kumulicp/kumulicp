<?php

namespace App\Console\Calls;

use App\AppInstance;
use App\Services\AppInstance\AvailabilityService;

/**
 * Rechecks the apps marked unavailable, each one every
 * AvailabilityService::RECHECK_MINUTES after it was last checked (so the
 * schedule can be tighter than that). It runs until an app answers, however
 * long that takes.
 */
class CheckUnavailableAppInstances
{
    public function __invoke(): void
    {
        $service = app(AvailabilityService::class);

        foreach (AppInstance::unavailable()->with('organization')->get() as $app) {
            if (! $service->isDueForRecheck($app)) {
                continue;
            }

            try {
                $service->recheck($app);
            } catch (\Throwable $e) {
                // One app that can't be checked mustn't stop the rest
                report($e);
            }
        }
    }
}
