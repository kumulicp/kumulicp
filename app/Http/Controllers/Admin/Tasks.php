<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DummyAction;
use App\Application;
use App\Enums\TaskStatus;
use App\Exceptions\Tasks\TaskOperationRefused;
use App\Http\Controllers\Controller;
use App\Jobs\RunSchedule;
use App\Services\Tasks\TaskOperator;
use App\Support\Facades\Action;
use App\Support\Redactor;
use App\Support\SafeErrorMessage;
use App\Support\TaskHelpers;
use App\Support\Time;
use App\Task;
use App\TaskEvent;
use App\TaskStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class Tasks extends Controller
{
    public function index()
    {
        $apps = Application::all();

        return inertia()->render('Admin/TasksList', [
            'apps' => $apps->map(function ($app) {
                return [
                    'id' => $app->id,
                    'slug' => $app->slug,
                    'name' => $app->name,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.tasks.tasks'),
                ],
            ],
        ]);
    }

    public function api(Request $request)
    {
        $tasks = Task::orderByDesc('created_at');
        if ($request->organization) {
            $tasks->where('organization_id', $request->organization);
        }
        if ($request->app) {
            $tasks->where('application_id', $request->app);
        }
        if ($status = TaskStatus::tryFrom((string) $request->status)) {
            $tasks->where('status', $status->value);
        }
        if ($request->app_instance) {
            $tasks->where('app_instance_id', $request->app_instance);
        }
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $tasks = $tasks->with(['application', 'version', 'organization', 'app_instance'])->paginate($perPage);

        return [
            'tasks' => $tasks->map(function ($task) {
                $time = new Time;
                if ($task->status->isOpen()) {
                    $duration = Time::duration($task->created_at, time());

                } else {
                    $duration = Time::duration($task->created_at, $task->updated_at);
                }

                return [
                    'id' => $task->id,
                    'organization' => $task->organization ? $task->organization->name : '',
                    'application' => $task->application ? $task->application->name.($task->version ? ' ('.$task->version->name.')' : '') : '',
                    'version' => $task->version ? $task->version->name : '',
                    'status' => $task->status->value,
                    'time' => $duration,
                    'description' => $task->description,
                    'error_message' => SafeErrorMessage::scrub($task->error_message),
                    'can_retry' => $task->status->isRetryable(),
                    'can_cancel' => $task->status->isCancellable(),
                    'can_delete' => $task->status->isDeletable(),
                ];
            }),
            'meta' => [
                'total' => $tasks->total(),
                'pages' => $tasks->lastPage(),
                'page' => $tasks->currentPage(),
            ],
        ];
    }

    /**
     * One task: its history, steps, what it waits for, and what an admin can do.
     */
    public function show(Task $task)
    {
        $task->load(['organization', 'application', 'version', 'app_instance']);

        $describe = fn (Task $other) => [
            'id' => $other->id,
            'description' => $other->description,
            'status' => $other->status->value,
            'gate' => $other->pivot->gate,
        ];

        return inertia()->render('Admin/TaskView', [
            'task' => [
                'id' => $task->id,
                'description' => $task->description,
                'status' => $task->status->value,
                'action' => $task->action(),
                'organization' => $task->organization ? ['id' => $task->organization->id, 'name' => $task->organization->name] : null,
                'application' => $task->application ? $task->application->name.($task->version ? ' ('.$task->version->name.')' : '') : null,
                'app_instance' => $task->app_instance?->label,
                'attempts' => $task->attempts,
                'background' => (bool) $task->background,
                'error_code' => $task->error_code,
                'error_message' => SafeErrorMessage::scrub($task->error_message),
                'blocked_from' => $task->blocked_from,
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
                'queued_at' => $task->queued_at?->toIso8601String(),
                'claimed_at' => $task->claimed_at?->toIso8601String(),
                'next_attempt_at' => $task->next_attempt_at?->toIso8601String(),
                'deadline_at' => $task->deadline_at?->toIso8601String(),
                'can_retry' => $task->status->isRetryable(),
                'can_cancel' => $task->status->isCancellable(),
                'can_delete' => $task->status->isDeletable(),
            ],
            'events' => $task->events()->get()->map(fn (TaskEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'from_status' => $event->from_status?->value,
                'to_status' => $event->to_status?->value,
                'attempt' => $event->attempt,
                'message' => SafeErrorMessage::scrub($event->message),
                'context' => $event->context,
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
            'steps' => $task->steps()->get()->map(fn (TaskStep $step) => [
                'name' => $step->name,
                'status' => $step->status,
                'started_at' => $step->started_at?->toIso8601String(),
                'finished_at' => $step->finished_at?->toIso8601String(),
                'seconds' => $step->started_at && $step->finished_at ? (int) round($step->started_at->diffInSeconds($step->finished_at, true)) : null,
                'error' => SafeErrorMessage::scrub($step->error),
            ]),
            'waiting_for' => $task->dependencies()->get()->map($describe),
            'waited_on_by' => $task->dependents()->get()->map($describe),
            'values' => $this->displayValues($task->customValues()),
            'breadcrumbs' => [
                [
                    'label' => __('admin.tasks.tasks'),
                    'url' => '/admin/server/tasks',
                ],
                [
                    'label' => '#'.$task->id,
                ],
            ],
        ]);
    }

    /**
     * A task's custom values for an admin to read: secrets masked, long text
     * (a serialized job, say) cut short.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function displayValues(array $values): array
    {
        $cut = function (mixed $value) use (&$cut) {
            if (is_array($value)) {
                return array_map($cut, $value);
            }

            if (is_string($value) && mb_strlen($value) > 300) {
                return mb_substr($value, 0, 300).'… ('.mb_strlen($value).' characters)';
            }

            return $value;
        };

        return $cut(Redactor::redact($values));
    }

    public function retry(Task $task, TaskOperator $operator)
    {
        return $this->operate(fn () => $operator->retry($task, auth()->user()), __('admin.tasks.retried'));
    }

    public function cancel(Task $task, TaskOperator $operator)
    {
        return $this->operate(fn () => $operator->cancel($task, auth()->user()), __('admin.tasks.cancelled'));
    }

    public function destroy(Task $task, TaskOperator $operator)
    {
        return $this->operate(fn () => $operator->delete($task), __('admin.tasks.deleted'));
    }

    public function bulk_retry(Request $request, TaskOperator $operator)
    {
        return $this->bulk('retry', $request, $operator);
    }

    public function bulk_cancel(Request $request, TaskOperator $operator)
    {
        return $this->bulk('cancel', $request, $operator);
    }

    public function bulk_delete(Request $request, TaskOperator $operator)
    {
        return $this->bulk('delete', $request, $operator);
    }

    private function operate(callable $operation, string $message)
    {
        try {
            $operation();
        } catch (TaskOperationRefused $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        return ['status' => 'success', 'message' => $message];
    }

    private function bulk(string $operation, Request $request, TaskOperator $operator)
    {
        $tasks = Task::whereIn('id', (array) $request->input('tasks', []))->get();
        $result = $operator->bulk($operation, $tasks, auth()->user());

        return [
            'status' => 'success',
            'done' => $result['done'],
            'refused' => $result['refused'],
            'message' => $result['refused'] === []
                ? null
                : __('admin.tasks.bulk_result', ['done' => $result['done'], 'refused' => count($result['refused'])]),
        ];
    }

    public function retrieve()
    {
        $organization = auth()->user()->organization;

        $getTasks = new TaskHelpers;
        $response = $getTasks->getGroupStatus(['organization_id' => $organization->id, 'background' => 0]);

        return response()->json($response);
    }

    public function dummy()
    {
        $organization = auth()->user()->organization;

        $task = Action::execute(new DummyAction);

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.dummy'));
    }

    public function run_schedule()
    {
        RunSchedule::dispatch();

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.run_schedule'));
    }

    public function restart_queue()
    {
        Artisan::call('queue:restart');

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.restart_queue'));
    }
}
