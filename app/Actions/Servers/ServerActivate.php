<?php

namespace App\Actions\Servers;

use App\Actions\Action;
use App\Actions\Apps\ApplicationActivate;
use App\Application;
use App\AppPlan;
use App\Organization;
use App\Server;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class ServerActivate extends Action
{
    public $organization;

    public $slug = 'server_activate';

    public $app_version = '';

    public function __construct(Server $server, Application $app, AppPlan $plan, string $name)
    {
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->application = $app;
        $this->setCustomValues([
            'server_id' => $server->id,
            'plan_id' => $plan->id,
            'name' => $name,
        ]);
        $this->description = __('actions.activating_server', ['server' => $server->name]);
    }

    public static function run(Task $task)
    {
        $server = Server::find($task->getValue('server_id'));
        $plan = AppPlan::find($task->getValue('plan_id'));
        $server_activate = new self($server, $task->application, $plan, $task->getValue('name'));

        $activate_parent_app = null;

        if ($parent = $task->application->parent_app) {
            // TODO: Set plan properly
            $activate_parent_app = ActionFacade::execute(new ApplicationActivate($task->organization, $parent, $parent->plans->first(), [], null, label: $task->getValue('name').' '.$parent->name));
        }
        $activate_app = ActionFacade::execute(new ApplicationActivate($task->organization, $task->application, $plan, [], $activate_parent_app?->app_instance, label: $task->getValue('name').' '.$task->application->name), $activate_parent_app);

        $task->app_instance()->associate($activate_app->app_instance);
        $task->save();
        $server->app_instance()->associate($activate_app->app_instance);
        $server->save();

        return $server_activate;
    }

    public static function retry(Task $task)
    {
        $server = Server::find($task->getValue('server_id'));
        $plan = AppPlan::find($task->getValue('plan_id'));

        return new self($server, $task->application, $plan, $task->getValue('name'));
    }

    public static function complete(Task &$task)
    {
        if (! $task->app_instance) {
            $task->status = 'failed';
            $task->error_message = __('messages.rule.app_exists');
            $task->save();
        } elseif ($task->app_instance->status === 'active') {
            $task->complete();
            $task->groupNotified();
            $task->save();
        }
    }
}
