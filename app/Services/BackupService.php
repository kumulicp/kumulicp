<?php

namespace App\Services;

use App\AppInstance;
use App\BackupSchedule;
use App\Integrations\ServerManagers\AppDatabase\BackupInterface;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class BackupService
{
    private $drivers = [
        'database' => [
            'app_database' => BackupInterface::class,
        ],
    ];

    public function register(string $type, string $name, $class)
    {
        if (class_exists($class)) {
            Arr::set($this->drivers, $type.'.'.$name, $class);
        } else {
            throw new \Exception('Backup class doesn\'t exist');
        }
    }

    public function connect(OrgBackup $org_backup, ...$args)
    {
        $org_server = $org_backup->org_server;
        if ($this->driverExists($org_server)) {
            $driver = $this->driver($org_server);

            return new $driver($org_backup, ...$args);
        }

        $driver = $org_server->server?->interface;

        if ($driver) {
            throw new \Exception(__('messages.exception.no_backup_driver', ['driver' => $driver]));
        }
    }

    public function driverExists(OrgServer $org_server)
    {
        $type = $org_server->server?->type;
        $driver = $org_server->server?->interface;

        if (! $driver) {
            return false;
        }

        return array_key_exists($driver, $this->drivers[$type]) && class_exists($this->drivers[$type][$driver]);
    }

    private function driver(OrgServer $org_server)
    {
        $type = $org_server->server->type;
        $driver = $org_server->server->interface;

        return $this->drivers[$type][$driver];
    }

    private function backupServerSettings(RecurringBackup $recurring_backup)
    {
        // Not in use yet
    }

    public function schedule(
        BackupSchedule $backup_schedule,
        string $scheduled_at,
        string $type,
        int $delete_after,
        OrgServer $org_server,
        Organization $organization,
        AppInstance $app_instance,
    ) {
        // Check if backup should even be scheduled
        if ($org_server && ! $this->driverExists($org_server)) {
            return;
        }

        switch ($backup_schedule->recurring_backup?->delete_interval) {
            case 'backups':
                $delete_at = null;
                break;
            case 'days':
                $delete_at = (new Carbon($scheduled_at))->addDays($delete_after);
                break;
            case 'months':
                $delete_at = (new Carbon($scheduled_at))->addMonths($delete_after);
                break;
            default:
                $delete_at = (new Carbon($scheduled_at))->addDays($delete_after);
        }
        $org_backup = new OrgBackup;
        $org_backup->scheduled_backup_id = $backup_schedule->id;
        $org_backup->org_server_id = $org_server->id;
        $org_backup->organization_id = $organization->id;
        $org_backup->app_instance_id = $app_instance->id;
        $org_backup->action = 'backup';
        $org_backup->type = $type;
        $org_backup->scheduled_at = $scheduled_at;
        $org_backup->status = 'scheduled';
        $org_backup->delete_at = $delete_at;
        $org_backup->save();
    }
}
