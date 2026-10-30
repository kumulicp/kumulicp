<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Actions\Apps\ApplicationDelete;
use App\Enums\TaskStatus;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class DeleteOrganization extends Action
{
    public $slug = 'delete_organization';

    public $background = true;

    public TaskStatus $status = TaskStatus::PENDING;

    public function __construct(Organization $organization)
    {
        $this->organization = $organization;

        $this->description = __('actions.deleting_organization');
    }

    public static function run(Task $task)
    {
        $app_tasks = [];
        foreach ($task->organization->app_instances as $app) {
            if ($app_task = ActionFacade::execute(new ApplicationDelete($app, start_time: '00:00', end_time: '23:59'), $task)) {
                $app_tasks[] = $app_task;
            }
        }

        // The organization is only deleted once every app has been
        return (new self($task->organization))->waitFor(...$app_tasks);
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;

        return new self($organization);
    }

    public static function complete(Task $task)
    {
        // Polled only once the apps' delete tasks are complete or gone
        AccountManager::account($task->organization)->destroy();

        $task->organization->domains()->delete();
        $task->organization->app_instances()->delete();
        $task->organization->backups()->delete();
        $task->organization->domains()->delete();
        $task->organization->servers()->delete();
        $task->organization->tasks()->delete();
        $task->organization->users()->forceDelete();
        $task->organization->logs()->delete();
        $task->organization->delete();
        $task->delete();
    }
}
