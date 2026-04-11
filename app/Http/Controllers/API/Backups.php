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

        $results_to_string = json_encode([
            'job_id' => $request->input('job_id'),
            'status' => $request->input('result.status'),
            'action' => $request->input('result.action'),
            'backup_name' => $request->input('result.backup_name'),
        ]);

        if ($backup) {
            Log::info($results_to_string, ['organization_id' => $backup->organization_id]);
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
                Log::critical($request->all(), ['organization_id' => 1]);
                $backup->status = 'failed';
                $backup->save();
            }

            return response()->json(json_encode(['status' => 'success']), 200);
        }

        Log::critical(__('messages.failed')."! : {$results_to_string}", ['organization_id' => 1]);

        return response()->json(json_encode(['status' => 'failed']), 200);
    }
}
