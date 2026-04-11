<?php

namespace App\Console\Calls;

use App\OrgBackup;
use App\Support\Facades\Backup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class DeleteBackups
{
    public function __invoke()
    {
        $backups = OrgBackup::where('action', 'backup')
            ->where('status', 'completed')
            ->where(function (Builder $query) {
                $query->where('delete_at', '<', now())
                    ->orWhereNull('delete_at');
            })
            ->whereNull('deleted_at')
            ->get();

        $delete_intervals = [];
        $delete_ignores = [];

        foreach ($backups as $backup) {
            if (is_null($backup->delete_at) && $backup->backup_schedule?->recurring_backup?->delete_interval === 'backups') {
                $delete_intervals[$backup->scheduled_backup_id] = Arr::get($delete_intervals, $backup->scheduled_backup_id, $backup->backup_schedule->recurring_backup->delete_after);
                $backup_ignores[$backup->scheduled_backup_id] = Arr::get($delete_ignores, $backup->scheduled_backup_id, 0) + 1;

                if ($backup_ignores[$backup->scheduled_backup_id] <= $delete_intervals[$backup->scheduled_backup_id]) {
                    continue;
                }
            }
            $organization = $backup->organization;
            $running_backups = $organization->backups()->whereBetween('scheduled_at', [now()->subMinutes(60), now()->addMinutes(30)])->where('status', '!=', 'completed')->get();

            // Check if no backups are scheduled within the hour so no conflicts doesn't happen
            if (count($running_backups) == 0) {

                try {
                    Log::info(__('actions.delete_backup').": {$backup->backup_name}", ['organization_id' => $organization->id]);

                    $backup_interface = Backup::connect($backup);

                    $response = $backup_interface->delete();

                    $backup->job_id = $response['job_id'];
                    $backup->save();
                } catch (\Throwable $e) {
                    report($e);
                    $backup->status = 'failed';
                    $backup->save();
                }
            }
        }
    }
}
