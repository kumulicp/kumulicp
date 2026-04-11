<?php

namespace App\Support;

use App\Task;
use Illuminate\Support\Facades\DB;

class TaskHelpers
{
    public $count;

    public function groupTasks($filters = [])
    {
        $tasks = Task::selectRaw('tasks.*, IFNULL(action_slug,UUID()) as unq_group')
            ->where($filters)
            ->whereNot('status', 'complete')
            ->orderBy('updated_at', 'desc')
            ->groupBy('task_group')
            ->get();

        $this->count = $tasks->count();

        return $tasks;
    }

    public function getGroupStatus($filters)
    {
        $tasks = $this->groupTasks($filters);
        $task_count = $this->count;

        $response = [];
        foreach ($tasks as $task) {
            $time = '';
            $status = 'In Progress';
            if ($task->status == 'failed') {
                $status = 'Failed';
            }
            $response[] = [
                'id' => $task->id,
                'description' => $task->description,
                'status' => $status,
            ];
        }

        return $response;
    }

    public static function systemTaskComplete($task)
    {
        $updated = strtotime($task->updated_at);
        $next_five = (ceil($updated / 300) * 300) + 30;

        if (time() > $next_five) {
            return true;
        } else {
            return false;
        }
    }

    public static function pendingDomainChanges($organization)
    {
        $pending_domain_tasks = DB::table('tasks')
            ->where('status', '!=', 'complete')
            ->where('organization_id', $organization->id)
            ->where(function ($query) {
                $query->where('action_slug', 'domain_add')
                    ->orWhere('action_slug', 'domain_update')
                    ->orWhere('action_slug', 'domain_delete');
            })
            ->count();

        if ($pending_domain_tasks > 0) {
            return true;
        } else {
            return false;
        }
    }
}
