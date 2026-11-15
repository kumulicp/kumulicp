<?php

namespace App\Monitoring\Notifications;

use Spatie\UptimeMonitor\Notifications\Notifications\UptimeCheckFailed as PackageNotification;

/**
 * The package emails ERRORS_EMAIL when a monitor goes down. For an app's
 * monitor that is replaced by AvailabilityService's warning and unavailable
 * notifications, which go to the system admins and the organization's admins
 * and are sent once. A monitor added by hand still gets the package's email.
 *
 * Named like the package's class on purpose: it is matched to its event by
 * class name (see config/uptime-monitor.php).
 */
class UptimeCheckFailed extends PackageNotification
{
    public function isStillRelevant(): bool
    {
        return parent::isStillRelevant() && $this->getMonitor()->app_instance_id === null;
    }
}
