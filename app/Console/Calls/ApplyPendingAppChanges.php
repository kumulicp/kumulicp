<?php

namespace App\Console\Calls;

use App\AppInstance;
use App\Services\AppInstance\PendingAppChanges;

/**
 * What waited for an app to be active is normally done the moment the app saves as active (see
 * AppInstance::booted()). This catches the apps whose status was set some way that doesn't announce it, so
 * nothing is left waiting for good. Doing it twice is not a risk: the work is taken and cleared in one step.
 */
class ApplyPendingAppChanges
{
    public function __invoke(): void
    {
        $changes = app(PendingAppChanges::class);

        foreach (AppInstance::where('status', 'active')->whereNotNull('settings->'.PendingAppChanges::SETTING)->get() as $app) {
            try {
                $changes->applyIfDue($app);
            } catch (\Throwable $e) {
                // One app mustn't stop the rest
                report($e);
            }
        }
    }
}
