<?php

namespace App\Console\Calls;

use App\Services\Application\ApplicationReadiness;

/**
 * An app's saved status follows its readiness as versions, plans and servers
 * change (see ReadinessObserver). This catches anything that changed without
 * a model event, so the status can't stay wrong for long.
 */
class SyncApplicationReadiness
{
    public function __invoke()
    {
        ApplicationReadiness::syncAll();
    }
}
