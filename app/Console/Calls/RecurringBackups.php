<?php

namespace App\Console\Calls;

use App\Application;
use App\AppInstance;
use App\BackupSchedule;
use App\Organization;
use App\OrgServer;
use App\RecurringBackup;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\Backup;
use Illuminate\Support\Facades\Log;

class RecurringBackups
{
    private $datetime;

    public function __invoke()
    {
        $recurring_backups = RecurringBackup::where('status', 'active')
            ->where(function ($query) {
                $query->where('last_scheduled_at', '<', now())
                    ->orWhereNull('last_scheduled_at');
            })
            ->get();
        $organizations = Organization::all();

        foreach ($recurring_backups as $backup) {
            // Don't create scheduled backup if future backup is already scheduled
            $backup_schedule = $backup->scheduled()->where('scheduled_at', '>', now())->count();

            if ($backup_schedule > 0) {
                continue;
            }

            // Nothing could run it. Turned off, once, rather than skipped every minute.
            if (! Backup::supports($backup->server->type)) {
                $backup->status = 'inactive';
                $backup->save();

                Log::warning(__('messages.backups.recurring_unsupported', [
                    'server' => $backup->server->name,
                    'type' => $backup->server->type,
                ]));

                continue;
            }

            $this->datetime = $backup->nextDateTime();

            $backup_schedule = new BackupSchedule;
            $backup_schedule->scheduled_at = $this->datetime;
            $backup_schedule->recurring_backup_id = $backup->id;
            $backup_schedule->save();

            // Backup applications for each organization
            if (in_array($backup->server->type, ['web', 'database'])) {
                // Backup specific application
                if (isset($backup->application_id)) {
                    $this->backupApplication($backup, $backup_schedule);
                }
                // Backup all applications
                else {
                    $this->backupAllApps($backup, $backup_schedule);
                }
            }

            $backup->last_scheduled_at = $this->datetime;
            $backup->save();
        }
    }

    private function backupApplication(RecurringBackup $recurring_backup, BackupSchedule $backup_schedule)
    {
        $application = Application::find($recurring_backup->application_id);
        $server_type = $recurring_backup->server->type;
        $server_id = $server_type.'_server_id';

        if ($recurring_backup->organization_id) {
            $app_instances = $application->instances()
                ->where('organization_id', $recurring_backup->organization_id)
                ->where('application_id', $recurring_backup->application_id)
                ->notDeactivated()
                ->get();
        } else {
            $app_instances = $application->instances()->notDeactivated()->get();
        }

        foreach ($app_instances as $app_instance) {
            $backup_server = AppFacade::instance($app_instance)->backupServer($server_type);
            $this->schedule($recurring_backup, $backup_schedule, $app_instance->organization, $app_instance, $backup_server->get());
        }
    }

    private function backupAllApps(RecurringBackup $recurring_backup, BackupSchedule $backup_schedule)
    {
        $server_type = $recurring_backup->type;
        $server_id = $server_type.'_server_id';

        if ($organization = $recurring_backup->organization) {
            $app_instances = $organization->app_instances()->notDeactivated()->get();
            foreach ($app_instances as $app_instance) {
                $backup_server = AppFacade::instance($app_instance)->backupServer($server_type);
                $this->schedule($recurring_backup, $backup_schedule, $organization, $app_instance, $backup_server->get());
            }

            return;
        }

        $organizations = Organization::notDeactivated()->get();
        foreach ($organizations as $organization) {
            $app_instances = $organization->app_instances()->notDeactivated()->get();
            foreach ($app_instances as $app_instance) {
                if ($backup_server = AppFacade::instance($app_instance)->backupServer($recurring_backup->type)) {
                    $this->schedule($recurring_backup, $backup_schedule, $organization, $app_instance, $backup_server->get());
                }
            }
        }
    }

    /**
     * Schedules one app's backup. An app whose backup server has no driver is skipped
     * and said so in the log; it must not stop the backups of the others.
     */
    private function schedule(RecurringBackup $recurring_backup, BackupSchedule $backup_schedule, Organization $organization, AppInstance $app_instance, OrgServer $backup_server): void
    {
        if (! Backup::driverExists($backup_server)) {
            Log::warning(__('messages.backups.skipped_no_driver', [
                'app' => $app_instance->label,
                'server' => $backup_server->server?->name,
            ]), ['organization_id' => $organization->id]);

            return;
        }

        Backup::schedule(
            $backup_schedule,
            scheduled_at: $this->datetime,
            organization: $organization,
            org_server: $backup_server,
            app_instance: $app_instance,
            type: $recurring_backup->type,
            delete_after: $recurring_backup->delete_after,
        );
    }
}
