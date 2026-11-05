<?php

namespace App\Console\Calls;

use App\Monitoring\AppMonitors;

/**
 * Safety net for AppInstanceObserver: makes sure every app has the monitor it
 * should (pointing at its current address) and that no monitor outlives its
 * app.
 */
class SyncAppMonitors
{
    public function __invoke(): void
    {
        app(AppMonitors::class)->reconcile();
    }
}
