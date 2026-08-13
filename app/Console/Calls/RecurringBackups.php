<?php

namespace App\Console\Calls;

use App\Application;
use App\BackupSchedule;
use App\Organization;
use App\RecurringBackup;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\Backup;

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
            Backup::schedule(
                $backup_schedule,
                scheduled_at: $this->datetime,
                organization: $app_instance->organization,
                org_server: $backup_server->get(),
                app_instance: $app_instance,
                type: $recurring_backup->type,
                delete_after: $recurring_backup->delete_after,
            );
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
                Backup::schedule(
                    $backup_schedule,
                    scheduled_at: $this->datetime,
                    organization: $recurring_backup->organization,
                    org_server: $backup_server->get(),
                    app_instance: $app_instance,
                    type: $recurring_backup->type,
                    delete_after: $recurring_backup->delete_after,
                );
            }

            return;
        }

        $organizations = Organization::notDeactivated()->get();
        foreach ($organizations as $organization) {
            $app_instances = $organization->app_instances()->notDeactivated()->get();
            foreach ($app_instances as $app_instance) {
                if ($backup_server = AppFacade::instance($app_instance)->backupServer($recurring_backup->type)) {
                    Backup::schedule(
                        $backup_schedule,
                        scheduled_at: $this->datetime,
                        organization: $organization,
                        org_server: $backup_server->get(),
                        app_instance: $app_instance,
                        type: $recurring_backup->type,
                        delete_after: $recurring_backup->delete_after,
                    );
                }
            }
        }
    }
}
