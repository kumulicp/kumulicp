<?php

namespace App\Http\Controllers\API\Account;

use App\Http\Controllers\Controller;
use App\Support\Facades\Organization;
use App\Support\TaskHelpers;
use App\Task;

class Tasks extends Controller
{
    public function index()
    {
        $organization = Organization::account();

        $tasks = new TaskHelpers;
        $response = $tasks->get_group_status(['organization_id' => $organization->id, 'background' => 0]);

        return response()->json($response);
    }

    public function delete($task)
    {
        $organization = Organization::account();
        $task = Task::where('id', $task)->first();

        $remove_group = Task::where('task_group', $task->task_group)
            ->where('organization_id', $organization->id)
            ->where('status', 'complete')
            ->delete();
    }
}
