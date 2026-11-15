<?php

namespace App\Console\Calls;

use App\Monitoring\AppMonitors;

/**
 * Safety net for AppInstanceObserver: makes sure every app has the monitor it
 * should, pointing at its current address.
 */
class SyncAppMonitors
{
    public function __invoke(): void
    {
        app(AppMonitors::class)->reconcile();
    }
}
