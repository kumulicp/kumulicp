<?php

namespace App\Notifications;

/**
 * The app answers again after being marked unavailable. Sent once, however the
 * app was found to be back (a monitor check or an API call that went through).
 * Pass `since` (when it stopped answering): the availability settings that
 * hold it are cleared as the app is restored, before this is rendered.
 */
class AppInstanceAvailable extends AppInstanceAvailabilityNotification
{
    protected function key(): string
    {
        return 'app_available';
    }
}
