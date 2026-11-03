<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Task;
use Illuminate\Support\Facades\DB;

class TaskHelpers
{
    // Why a task is being retried, when it is because something went wrong.
    // (TaskRunner::retryLater() also uses 'waiting' for a routine poll of a
    // slow job, which isn't a problem.) `attempts` can't tell the two apart:
    // it counts every run, and an action with a postGenerate() starts at 1.
    private const RETRY_ERROR_CODES = ['connection_failed', 'error'];

    public $count;

    public function groupTasks($filters = [])
    {
        $uuidExpr = DB::getDriverName() === 'sqlite' ? 'lower(hex(randomblob(16)))' : 'UUID()';
        $tasks = Task::selectRaw("tasks.*, IFNULL(action_slug,{$uuidExpr}) as unq_group")
            ->where($filters)
            ->whereNot('status', TaskStatus::COMPLETE->value)
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
            $status = 'In Progress';
            $message = 'In Progress';
            if (in_array($task->status, [TaskStatus::FAILED, TaskStatus::BLOCKED], true) || in_array($task->error_code, self::RETRY_ERROR_CODES, true)) {
                $status = 'Failed';
                $message = __('messages.action.tech_difficulties');
            }
            $response[] = [
                'id' => $task->id,
                'description' => $task->description,
                'status' => $status,
                'message' => $message,
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
            ->where('status', '!=', TaskStatus::COMPLETE->value)
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
