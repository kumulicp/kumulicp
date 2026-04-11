<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Actions\Apps\ApplicationDeactivate;
use App\Notifications\OrganizationDeactivated;
use App\Organization;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class DeactivateOrganization extends Action
{
    public $slug = 'deactivate_organization';

    public $background = true;

    public function __construct(Organization $organization)
    {
        $this->organization = $organization;
        $app_tasks = [];

        $this->description = __('actions.deactivating_organization', ['organization' => $organization->name]);
    }

    public static function run(Task $task)
    {
        $task->organization->status = 'deactivated';
        $task->organization->save();

        $app_tasks = [];
        foreach ($task->organization->app_instances as $app) {
            $app_task = ActionFacade::execute(new ApplicationDeactivate($app), $task);
            $app_tasks[] = $app_task->id;
        }

        $self = new self($task->organization);
        $self->addCustomValue(['waiting_for' => $app_tasks]);

        return $self;
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;

        return new self($organization);
    }

    public static function complete(Task $task)
    {
        $task->organization->notifyAdmins(new OrganizationDeactivated);
        $task->notified();
        $task->complete();
    }
}
