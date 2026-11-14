<?php

namespace App\Actions\Organizations;

use App\Actions\Action;
use App\Actions\Apps\ApplicationDelete;
use App\Enums\TaskStatus;
use App\Mail\OrganizationDeleted;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\Mail;

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
        // Who to tell has to be found first: once the account is destroyed there are no admins left to ask
        $recipients = self::adminRecipients($task->organization);
        $organization_name = $task->organization->name;
        $reason = $task->organization->deactivationReasonText();

        // Polled only once the apps' delete tasks are complete or gone
        AccountManager::account($task->organization)->destroy();

        // Deleting these tasks (this one included) is how it says it has finished:
        // the tasks waiting for them, like a test step's clean-up, are told at the end
        $runner = app(TaskRunner::class);
        $waiting = $runner->dependentsOf($task->organization->tasks()->pluck('id')->push($task->id));

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

        $runner->wake($waiting);

        self::notifyAdmins($recipients, $organization_name, $reason);
    }

    /**
     * @return array<int, array{email: string, name: ?string}>
     */
    private static function adminRecipients(Organization $organization): array
    {
        // Throwaway organizations made by version tests have made-up addresses
        if ($organization->isTest()) {
            return [];
        }

        $recipients = [];

        try {
            foreach ($organization->admins() as $admin) {
                if ($email = $admin->attribute('email')) {
                    $recipients[$email] = ['email' => $email, 'name' => $admin->attribute('name')];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return array_values($recipients);
    }

    /**
     * The organization is gone by now: a failure to email one admin must not
     * undo that or stop the others being told.
     */
    private static function notifyAdmins(array $recipients, string $organization_name, ?string $reason): void
    {
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient['email'], $recipient['name'])->send(new OrganizationDeleted($organization_name, $reason));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
