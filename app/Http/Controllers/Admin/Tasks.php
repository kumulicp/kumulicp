<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DummyAction;
use App\Application;
use App\Http\Controllers\Controller;
use App\Support\Facades\Action;
use App\Support\TaskHelpers;
use App\Support\Time;
use App\Task;
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
        if ($request->status) {
            $tasks->where('status', $request->status);
        }
        if ($request->app_instance) {
            $tasks->where('app_instance_id', $request->app_instance);
        }
        $tasks = $tasks->with(['application', 'version', 'organization', 'app_instance'])->paginate(20);

        return [
            'tasks' => $tasks->map(function ($task) {
                $time = new Time;
                if ($task->status != 'complete') {
                    $duration = Time::duration($task->created_at, time());

                } else {
                    $duration = Time::duration($task->created_at, $task->updated_at);
                }

                return [
                    'id' => $task->id,
                    'organization' => $task->organization ? $task->organization->name : '',
                    'application' => $task->application ? $task->application->name.($task->version ? ' ('.$task->version->name.')' : '') : '',
                    'version' => $task->version ? $task->version->name : '',
                    'status' => $task->status,
                    'time' => $duration,
                    'description' => $task->description,
                    'error_message' => $task->error_message,
                ];
            }),
            'meta' => [
                'total' => $tasks->total(),
                'pages' => $tasks->lastPage(),
                'page' => $tasks->currentPage(),
            ],
        ];
    }

    public function delete_api(Request $request, Task $task)
    {
        $task->delete();

        return [
            'status' => 'success',
        ];
    }

    public function restart(Task $task)
    {
        Action::retry($task);

        return redirect('/admin/server/tasks');
    }

    public function retrieve()
    {
        $organization = auth()->user()->organization;

        $getTasks = new TaskHelpers;
        $response = $getTasks->getGroupStatus(['organization_id' => $organization->id, 'background' => 0]);

        return response()->json($response);
    }

    public function delete($task)
    {
        $remove = Task::where('id', $task)
            ->delete();

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.deleted'));
    }

    public function dummy()
    {
        $organization = auth()->user()->organization;

        $task = Action::execute(new DummyAction);

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.dummy'));
    }

    public function run_schedule()
    {
        Artisan::call('schedule:run');

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.run_schedule'));
    }

    public function restart_queue()
    {
        Artisan::call('queue:restart');

        return redirect('/admin/server/tasks')->with('success', __('admin.tasks.restart_queue'));
    }
}
