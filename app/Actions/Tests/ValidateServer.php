<?php

namespace App\Actions\Tests;

use App\Actions\Action;
use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppInstance;
use App\Server;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class ValidateServer extends Action
{
    public $slug = 'validate_server';

    public function __construct(Server $server)
    {
        $test_app = AppInstance::where('test_app', 1)->first();

        $task_activate = ActionFacade::execute(new ApplicationActivate($test_app->organization, $test_app->application, $test_app->plan));
        $task_update = ActionFacade::execute(new ApplicationUpdate($test_app), $task_activate);
        $task_upgrade = ActionFacade::execute(new ApplicationUpgrade($test_app, $test_app->version), $task_activate);
        $task_delete = ActionFacade::execute(new ApplicationDelete($test_app), $task_activate);

        $this->description = __('actions.validate_server');

        $values = $this->setCustomValues([
            'server_id' => $server->id,
            'activate' => $task_activate->id,
            'update' => $task_update->id,
            'upgrade' => $task_upgrade->id,
            'delete' => $task_delete->id,
        ]);
    }

    public static function complete(Task $task)
    {
        $values = $task->customValues();

        foreach (['activate', 'update', 'upgrade', 'delete'] as $key) {
            $sub_task = Task::find($values[$key]);
            if (! $sub_task || $sub_task->status != 'complete') {
                return;
            }
        }

        $server = Server::find($values['server_id']);
        $server->status = 'active';
        $server->save();

        $task->complete();
        $task->groupNotified();
    }
}
