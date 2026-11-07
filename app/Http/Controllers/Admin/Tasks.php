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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

    // How many levels of waiting tasks the tree view follows
    private const TREE_MAX_DEPTH = 25;

    // Task ids per query when walking the dependency table
    private const TREE_CHUNK = 500;

    public function api(Request $request)
    {
        $tasks = $this->filtered_tasks($request);
        $per_page = min(max((int) $request->input('per_page', 20), 1), 100);

        if ($request->input('view') === 'tree') {
            return $this->tree($tasks, $per_page);
        }

        $tasks = $tasks->orderByDesc('created_at')
            ->with(['application', 'version', 'organization', 'app_instance'])
            ->paginate($per_page);

        return [
            'tasks' => $tasks->map(fn ($task) => $this->present($task)),
            'meta' => [
                'total' => $tasks->total(),
                'pages' => $tasks->lastPage(),
                'page' => $tasks->currentPage(),
            ],
        ];
    }

    /**
     * @return Builder<Task>
     */
    private function filtered_tasks(Request $request): Builder
    {
        $tasks = Task::query();
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

        return $tasks;
    }

    /**
     * One task as the task list shows it.
     *
     * @return array<string, mixed>
     */
    private function present(Task $task): array
    {
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
    }

    /**
     * The task list as a tree: a task is the parent of the tasks it waits for.
     * Pages are made of top-level tasks (nothing waits for them), each with all
     * it waits for, so one job's tasks stay together. With filters, a top-level
     * task is listed when it or anything beneath it matches.
     *
     * @param  Builder<Task>  $matching
     * @return array<string, mixed>
     */
    private function tree(Builder $matching, int $per_page): array
    {
        $roots = $this->roots_query($matching)
            ->orderByDesc('created_at')
            ->with(['application', 'version', 'organization', 'app_instance'])
            ->paginate($per_page);

        // Everything beneath this page's tasks, a level at a time
        $tasks = $roots->getCollection()->keyBy('id');
        $children = [];
        $level = $tasks->keys()->all();
        for ($depth = 0; $level !== [] && $depth < self::TREE_MAX_DEPTH; $depth++) {
            $edges = collect();
            foreach (array_chunk($level, self::TREE_CHUNK) as $chunk) {
                $edges = $edges->concat(DB::table('task_dependencies')->whereIn('task_id', $chunk)->get(['task_id', 'depends_on_task_id']));
            }
            foreach ($edges as $edge) {
                $children[(int) $edge->task_id][] = (int) $edge->depends_on_task_id;
            }

            $unseen = $edges->pluck('depends_on_task_id')->unique()->diff($tasks->keys())->values()->all();
            foreach (array_chunk($unseen, self::TREE_CHUNK) as $chunk) {
                $tasks = $tasks->union(Task::whereKey($chunk)->with(['application', 'version', 'organization', 'app_instance'])->get()->keyBy('id'));
            }
            $level = $unseen;
        }

        $build = function (int $id, array $path) use (&$build, $tasks, $children) {
            $node = $this->present($tasks[$id]);
            $node['children'] = [];
            foreach ($children[$id] ?? [] as $child_id) {
                // A task that is waiting for itself can't happen, but must not loop if it did
                if ($tasks->has($child_id) && ! in_array($child_id, $path, true)) {
                    $node['children'][] = $build($child_id, [...$path, $id]);
                }
            }

            return $node;
        };

        return [
            'tasks' => $roots->getCollection()->map(fn ($task) => $build($task->id, []))->values(),
            'meta' => [
                'total' => $roots->total(),
                'pages' => $roots->lastPage(),
                'page' => $roots->currentPage(),
            ],
        ];
    }

    /**
     * The tasks that sit at the top of the tree: nothing waits for them. With
     * filters, only those holding a task that matches.
     *
     * @param  Builder<Task>  $matching
     * @return Builder<Task>
     */
    private function roots_query(Builder $matching): Builder
    {
        if ($matching->getQuery()->wheres === []) {
            return Task::query()->whereNotIn('id', DB::table('task_dependencies')->select('depends_on_task_id'));
        }

        // Walk up from the matching tasks to the tasks that wait for them
        $roots = [];
        $seen = [];
        $level = $matching->pluck('id')->all();
        for ($depth = 0; $level !== [] && $depth <= self::TREE_MAX_DEPTH; $depth++) {
            $seen = array_merge($seen, $level);
            $parents = [];
            foreach (array_chunk($level, self::TREE_CHUNK) as $chunk) {
                $edges = DB::table('task_dependencies')->whereIn('depends_on_task_id', $chunk)->get(['task_id', 'depends_on_task_id']);
                $roots = array_merge($roots, array_diff($chunk, $edges->pluck('depends_on_task_id')->all()));
                $parents = array_merge($parents, $edges->pluck('task_id')->all());
            }
            $level = array_values(array_diff(array_unique($parents), $seen));
        }

        return Task::query()->whereIn('id', array_values(array_unique($roots)));
    }

    /**
     * One task: its history, steps, what it waits for, and what an admin can do.
     */
    public function show(Task $task)
    {
        $task->load(['organization', 'application', 'version', 'app_instance']);

        $describe = fn ($other) => [
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
                'created_at' => $event->created_at->toIso8601String(),
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
