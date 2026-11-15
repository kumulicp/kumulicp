<?php

namespace App\Monitoring;

use App\AppInstance;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Spatie\UptimeMonitor\Models\Monitor;

/**
 * spatie/laravel-uptime-monitor's monitor, plus the app instance it watches
 * (null for a monitor added by hand, which behaves exactly as the package's).
 * Set as `monitor_model` in config/uptime-monitor.php, so the package's own
 * commands and queries return this class.
 *
 * @property int|null $app_instance_id
 * @property-read AppInstance|null $appInstance
 */
class AppMonitor extends Monitor
{
    // Not app_monitors, which is what the class name would give
    protected $table = 'monitors';

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }

    /**
     * The package checks a monitor that is down on every run of
     * monitor:check-uptime (every minute). An app's monitor keeps to its
     * interval whether it is up or down: that interval is how often a down app
     * is rechecked, and hammering one that is struggling doesn't help it.
     */
    public function shouldCheckUptime(): bool
    {
        if ($this->app_instance_id === null) {
            return parent::shouldCheckUptime();
        }

        if (! $this->uptime_check_enabled) {
            return false;
        }

        if ($this->uptime_status == UptimeStatus::NOT_YET_CHECKED || $this->uptime_last_check_date === null) {
            return true;
        }

        return (int) $this->uptime_last_check_date->diffInMinutes(absolute: true) >= (int) $this->uptime_check_interval_in_minutes;
    }
}
