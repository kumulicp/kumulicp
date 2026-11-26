<?php

namespace App\Services;

use App\AppInstance;
use App\BackupSchedule;
use App\Contracts\BackupServerTargetContract;
use App\Exceptions\NoBackupDriverException;
use App\Integrations\ServerManagers\AppDatabase\BackupInterface;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
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

    /**
     * The types (of the server being backed up) there is a backup driver for.
     *
     * @return array<int, string>
     */
    public function supportedTypes(): array
    {
        return array_keys(array_filter($this->drivers));
    }

    public function supports(string $type): bool
    {
        return in_array($type, $this->supportedTypes(), true);
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
        return $org_server->server ? $this->driverExistsFor($org_server->server) : false;
    }

    public function driverExistsFor(Server $server)
    {
        $type = $server->type;
        $driver = $server->interface;

        if (! $driver) {
            return false;
        }

        return array_key_exists($driver, $this->drivers[$type] ?? []) && class_exists($this->drivers[$type][$driver]);
    }

    /**
     * Whether backups can be dumped from / restored into this specific server
     * (see BackupServerTargetContract), as database server moves need.
     */
    public function supportsServerTargets(Server $server): bool
    {
        return $this->driverExistsFor($server)
            && is_subclass_of($this->drivers[$server->type][$server->interface], BackupServerTargetContract::class);
    }

    private function driver(OrgServer $org_server)
    {
        $type = $org_server->server->type;
        $driver = $org_server->server->interface;

        return $this->drivers[$type][$driver];
    }

    public function schedule(
        BackupSchedule $backup_schedule,
        string $scheduled_at,
        string $type,
        int $delete_after,
        OrgServer $org_server,
        Organization $organization,
        AppInstance $app_instance,
    ): OrgBackup {
        // A backup nothing can run is an error, not a backup that was quietly left out.
        // Callers that go through many servers check driverExists() first, to skip.
        if (! $this->driverExists($org_server)) {
            throw NoBackupDriverException::for($org_server);
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

        return $org_backup;
    }

    /**
     * The server an app's database is backed up from, or null when it has no
     * database server or there is no backup driver for it.
     */
    public function databaseBackupServerFor(AppInstance $app_instance): ?OrgServer
    {
        $database_server = $app_instance->database_server;

        if (! $database_server || ! $this->driverExists($database_server)) {
            return null;
        }

        $backup_server = (new OrgServerService($database_server))->backupServer();

        return $backup_server && $this->driverExists($backup_server->get()) ? $backup_server->get() : null;
    }

    /**
     * Backs the app's database up as soon as the backup runner next looks
     * (ActivateBackup runs every minute), keeping the backup for $keep_days.
     *
     * @throws NoBackupDriverException when nothing could run the backup
     */
    public function runNow(AppInstance $app_instance, int $keep_days): OrgBackup
    {
        $org_server = $this->databaseBackupServerFor($app_instance);

        if (! $org_server) {
            throw new NoBackupDriverException(__('admin.backups.denied.no_driver'));
        }

        $now = now();
        $schedule = BackupSchedule::create(['scheduled_at' => $now]);

        return $this->schedule(
            $schedule,
            scheduled_at: $now->toDateTimeString(),
            type: 'database',
            delete_after: $keep_days,
            org_server: $org_server,
            organization: $app_instance->organization,
            app_instance: $app_instance,
        );
    }

    /**
     * Whether a database backup can be restored into another database server
     * than the one it was taken from.
     */
    public function canRestoreTo(OrgBackup $backup, Server $server): bool
    {
        return $backup->type === 'database'
            && $backup->app_instance
            && $server->type === 'database'
            && $server->host
            && $this->supportsServerTargets($server);
    }

    /**
     * Queues the restore of a completed backup, by default to the server it was
     * taken from, or to $target_server's database server (adding the app's
     * database and user there; the app keeps using its current server).
     */
    public function queueRestore(OrgBackup $backup, ?Server $target_server = null): OrgBackup
    {
        $restore = new OrgBackup;
        $restore->organization_id = $backup->organization_id;
        $restore->app_instance_id = $backup->app_instance_id;
        $restore->scheduled_backup_id = $backup->scheduled_backup_id;
        $restore->action = 'restore';
        $restore->type = $backup->type;
        $restore->status = 'scheduled';
        $restore->scheduled_at = now();
        $restore->backup_name = $backup->backup_name;
        $restore->org_server_id = $backup->org_server_id;
        $restore->settings = $target_server
            ? ['target_org_server_id' => OrgServerService::forServer($backup->organization, $target_server)->get()->id]
            : null;
        $restore->save();

        return $restore;
    }
}
