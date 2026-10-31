<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Backups extends Controller
{
    public function update(Request $request)
    {
        $organization = Organization::account();

        $backup = OrgBackup::where('id', $request->input('job_id'))->first();

        $results = [
            'job_id' => $request->input('job_id'),
            'status' => $request->input('result.status'),
            'action' => $request->input('result.action'),
            'backup_name' => $request->input('result.backup_name'),
        ];

        if ($backup) {
            Log::info('Backup job result received', ['organization_id' => $backup->organization_id, 'event' => 'backup.result_received'] + $results);
            if ($request->input('result.status') == 'completed') {

                if ($request->input('result.action') === 'backup') {
                    $backup->status = 'completed';
                    $backup->completed_at = now();
                    $backup->backup_name = $request->input('result.backup_name');
                } elseif ($request->input('result.action') == 'restore') {
                    $backup->status = 'completed';
                    $backup->completed_at = now();
                } elseif ($request->input('result.action') == 'delete') {
                    $backup->status = 'deleted';
                    $backup->deleted_at = now();
                }

                $backup->save();
            } elseif ($request->input('result.status') === 'failed') {
                Log::critical('Backup job failed', ['organization_id' => $backup->organization_id, 'event' => 'backup.job_failed'] + $results);
                $backup->status = 'failed';
                $backup->save();
            }

            return response()->json(json_encode(['status' => 'success']), 200);
        }

        Log::critical('Backup job result for unknown backup', ['organization_id' => 1, 'event' => 'backup.job_unknown'] + $results);

        return response()->json(json_encode(['status' => 'failed']), 200);
    }
}
