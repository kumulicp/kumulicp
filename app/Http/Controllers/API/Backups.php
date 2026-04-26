<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Backups extends Controller
{
    /**
     * @OA\Post(
     *     path="/backup/update",
     *     summary="Update backup job status",
     *     description="Called by background workers to report the outcome of a backup, restore, or delete operation. Updates the corresponding OrgBackup record.",
     *     tags={"Backups"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"job_id"},
     *             @OA\Property(property="job_id", type="integer", description="ID of the OrgBackup record to update."),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="status",
     *                     type="string",
     *                     enum={"completed","failed"},
     *                     description="Outcome of the job."
     *                 ),
     *                 @OA\Property(
     *                     property="action",
     *                     type="string",
     *                     enum={"backup","restore","delete"},
     *                     description="Operation that was performed."
     *                 ),
     *                 @OA\Property(
     *                     property="backup_name",
     *                     type="string",
     *                     description="Name of the backup file (required when action is 'backup')."
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Result accepted",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"success","failed"}, example="success")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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
