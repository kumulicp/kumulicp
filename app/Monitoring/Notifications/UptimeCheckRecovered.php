<?php

namespace App\Monitoring\Notifications;

use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckRecovered as PackageNotification;

/**
 * The package emails ERRORS_EMAIL when a monitor that was down comes back. For
 * an app's monitor that email is dropped: AvailabilityService puts the app back
 * to the status it had, and the outage was never reported as an error email in
 * the first place. A monitor added by hand still gets the package's email.
 *
 * Named like the package's class on purpose: it is matched to its event by
 * class name (see config/uptime-monitor.php).
 */
class UptimeCheckRecovered extends PackageNotification
{
    public function isStillRelevant(): bool
    {
        return parent::isStillRelevant() && $this->getMonitor()->app_instance_id === null;
    }
}
