<?php

namespace App\Notifications;

/**
 * The app didn't answer the recheck: API calls to it are blocked until it
 * does. Sent once per outage.
 */
class AppInstanceUnavailable extends AppInstanceAvailabilityNotification
{
    protected function key(): string
    {
        return 'app_unavailable';
    }
}
