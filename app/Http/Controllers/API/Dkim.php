<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Organization;
use App\Task;
use Illuminate\Http\Request;

class Dkim extends Controller
{
    public function update(Request $request, $job_id)
    {
        $organization = Organization::account();
        $task = Task::where('action_slug', 'run_rancher_job')
            ->where('organization_id', $organization->id)
            ->whereJsonContains('custom_values->job_id', $job_id)
            ->first();

        if (! $task) {
            return response()->json(['status' => 'failed'], 404);
        }

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
