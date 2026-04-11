<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Actions\Apps\ApplicationDelete;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Database\Eloquent\Builder;

class DeleteOrganization extends Action
{
    public $slug = 'delete_organization';

    public $background = true;

    public $status = 'pending';

    public function __construct(Organization $organization)
    {
        $this->organization = $organization;
        $app_tasks = [];

        $this->description = __('actions.deleting_organization');
    }

    public static function run(Task $task)
    {
        $app_tasks = [];
        foreach ($task->organization->app_instances as $app) {
            $app_task = ActionFacade::execute(new ApplicationDelete($app, start_time: '00:00', end_time: '23:59'), $task);

            if ($app_task) {
                $app_tasks[] = $app_task->id;
            }
        }

        $self = new self($task->organization);
        $self->addCustomValue(['app_tasks' => $app_tasks]);

        return $self;
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;

        return new self($organization);
    }

    public static function complete(Task $task)
    {
        $app_tasks = Task::whereNot('status', 'complete')
            ->whereNot('id', $task->id)
            ->where(function (Builder $query) use ($task) {
                foreach ($task->getValue('app_tasks') as $app) {
                    $query->orWhere('id', $app);
                }
            })
            ->where('organization_id', $task->organization_id);

        if ($app_tasks->count() === 0) {
            $organization = AccountManager::account($task->organization)->destroy();

            $task->organization->domains()->delete();
            $task->organization->app_instances()->delete();
            $task->organization->backups()->delete();
            $task->organization->domains()->delete();
            $task->organization->servers()->delete();
            $task->organization->tasks()->delete();
            $task->organization->users()->forceDelete();
            $task->organization->logs()->delete();
            $task->organization->delete();
            $app_tasks->delete();
            $task->delete();
        }
    }
}
