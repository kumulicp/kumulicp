<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Organization;
use App\Task;
use Illuminate\Http\Request;

class Dkim extends Controller
{
    /**
     * @OA\Post(
     *     path="/dkim/{job_id}",
     *     summary="Update DKIM public key",
     *     description="Called by background workers to store the generated DKIM public key for a domain. Resolves the domain from the serialized job identified by job_id.",
     *     tags={"DKIM"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="job_id",
     *         in="path",
     *         required=true,
     *         description="Unique job ID stored in the Task custom_values payload.",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"dkim_public_key"},
     *             @OA\Property(
     *                 property="dkim_public_key",
     *                 type="string",
     *                 description="Raw DKIM public key string (e.g. 'p=MIIBIjANBg...'). Base64 padding spaces are normalized automatically."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DKIM key saved",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="complete")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Task not found for the given job_id")
     * )
     */
    public function update(Request $request, $job_id)
    {
        $organization = Organization::account();
        $task = Task::where('action_slug', 'run_rancher_job')->whereJsonContains('custom_values->job_id', $job_id)->first();
        $raw_key = $request->input('dkim_public_key');
        $explode = explode('=', $raw_key);
        $p = str_replace(' ', '+', end($explode));
        $end_key = key($explode);
        $explode[$end_key] = $p;
        $dkim_public_key = implode('=', $explode);

        $job = unserialize($task->getValue('job'));
        $domain = $job->domain;
        $domain->dkim_public_key = $dkim_public_key;
        $domain->save();

        return response()->json(['status' => 'complete'], 200);
    }
}
