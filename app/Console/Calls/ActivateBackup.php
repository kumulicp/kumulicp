<?php

namespace App\Console\Calls;

use App\OrgBackup;
use App\Support\Facades\Backup;
use Illuminate\Support\Facades\Log;

class ActivateBackup
{
    public function __invoke()
    {
        $backups = OrgBackup::where('status', 'scheduled')
            ->where('scheduled_at', '<', now())
            ->get();

        foreach ($backups as $backup) {
            if (! Backup::driverExists($backup->org_server)) {
                $backup->status = 'no_driver';
                $backup->save();
            }
            try {
                if ($backup_server = Backup::connect($backup)) {
                    if ($backup->action == 'backup') {
                        Log::info(__('labels.backup').": {$backup->backup_name}", ['organization_id' => $backup->organization_id]);
                        $this->logBackup($backup);
                        $response = $backup_server->run($backup, $backup_server);
                    } elseif ($backup->action == 'restore') {
                        Log::info(__('labels.restore').": {$backup->backup_name}", ['organization_id' => $backup->organization_id]);
                        $response = $backup_server->restore($backup, $backup_server);
                    }

                    $backup->job_id = $response['job_id'];
                    $backup->status = $response['status'];
                    $backup->save();
                } else {
                    $backup->status = 'no_backup_driver';
                    $backup->save();
                }
            } catch (\Throwable $e) {
                report($e);

                $backup->status = 'failed';
                $backup->save();

                Log::critical(__('labels.backup').' '.__('labels.failed').": {$backup->backup_name}", ['organization_id' => $backup->organization_id]);
            }
        }
    }

    private function logBackup($backup)
    {
        $what = '';
        $action = '';
        if ($backup->action == 'backup') {
            $action = __('actions.run_backup');
        } else {
            $action = __('actions.restore_backup');
        }

        if ($backup->type == 'email') {
            $what = __('actions.emails_from').' '.$backup->org_server->server->name;
        } elseif ($app_instance = $backup->app_instance) {
            $what = $app_instance->application->name.' '.$backup->type;
        }

        Log::info("$action: $what", ['organization_id' => $backup->organization_id]);
    }
}
