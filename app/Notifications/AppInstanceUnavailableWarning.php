<?php

namespace App\Notifications;

/**
 * The first failed API call to an app. Sent once; if the app doesn't answer
 * the recheck, AppInstanceUnavailable follows (also once).
 */
class AppInstanceUnavailableWarning extends AppInstanceAvailabilityNotification
{
    protected function key(): string
    {
        return 'app_unavailable_warning';
    }
}
