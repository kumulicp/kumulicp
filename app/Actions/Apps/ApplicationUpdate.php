<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\Events\Apps\AppInstanceUpdated;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use App\Updates\UpdateSteps;

class ApplicationUpdate extends Action
{
    public $slug = 'application_update';

    public static bool $long_running = true;

    /**
     * @param  array<int, string>|null  $steps  The keys of the update steps to run (see UpdateSteps). Without them
     *                                          the update does what it always did: roll out the release and run the
     *                                          update_settings job.
     */
    public function __construct(AppInstance $app_instance, ?array $steps = null)
    {
        if ($steps !== null) {
            $this->setCustomValues(['steps' => array_values($steps)]);
        }

        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->app_instance->status = 'updating';
        $this->app_instance->save();

        $this->description = __('actions.updating_app', ['app' => $app_instance->application->name]);
    }

    public static function run(Task $task)
    {
        if (is_array($task->getValue('steps'))) {
            return static::runSteps($task);
        }

        $app_instance = Application::instance($task->app_instance);
        $app_update = new self($app_instance->app_instance);

        // A shared child without multisite has no release of its own to
        // update -- the hub's own settings cover it entirely.
        if (! $app_instance->usesOwnResources()) {
            return $app_update;
        }

        $server = $app_instance->connect('web');

        if ($job = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'update_settings'), $task, true)) {
            $app_update->waitFor($job);
        }

        $server->update();

        return $app_update;
    }

    /**
     * The steps asked for, each a task of its own, after the steps they follow.
     * The update is complete when they all are.
     */
    private static function runSteps(Task $task): self
    {
        $app_instance = $task->app_instance;
        $keys = $task->getValue('steps');
        $steps = app(UpdateSteps::class)->for($app_instance)->only($keys);

        $tasks = [];
        foreach ($steps as $key => $step) {
            $action = new ApplicationUpdateStep($app_instance, $key);

            $earlier = array_filter(array_map(fn (string $before) => $tasks[$before] ?? null, $step->after()));
            if ($earlier) {
                $action->dependsOn(...array_values($earlier));
            }

            if ($started = ActionFacade::execute($action, $task, true)) {
                $tasks[$key] = $started;
            }
        }

        return (new self($app_instance, $keys))->waitFor(...array_values($tasks));
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance, $task->getValue('steps'));
    }

    public static function complete(Task &$task)
    {
        $app_instance = Application::instance($task->app_instance);

        // The steps are tasks of their own and are all complete by now
        if (is_array($task->getValue('steps'))) {
            // The steps changed the app too: start from what is stored
            $app_instance->app_instance->refresh();
            $app_instance->status = 'active';
            $app_instance->save();

            $task->complete();
            $task->groupNotified();

            event(new AppInstanceUpdated($app_instance->app_instance));

            return;
        }

        // A shared child without multisite has nothing of its own to wait
        // on -- the hub's own update task tracks completion for it.
        if (! $app_instance->usesOwnResources()) {
            $app_instance->status = 'active';
            $app_instance->save();

            $task->complete();
            $task->groupNotified();

            event(new AppInstanceUpdated($app_instance->app_instance));

            return;
        }

        $child_app = null;

        if ($parent_app = $app_instance->parent) {
            $child_app = $app_instance;
            $app_instance = Application::instance($parent_app);
        }

        if ($app_instance && $server = $app_instance->connect('web')) {

            if ($server->isActive()) {

                $app_instance->status = 'active';
                $app_instance->save();

                // If main app being updated is a child app, also change status back to active
                if ($child_app) {
                    $child_app->status = 'active';
                    $child_app->save();
                }

                $task->complete();
                $task->groupNotified();

                event(new AppInstanceUpdated($app_instance->app_instance));
            }
        }
    }
}
