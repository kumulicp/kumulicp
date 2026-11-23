<?php

namespace App\Actions\Apps\SsoMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationSsoMove;
use App\Actions\Apps\ApplicationSSOSetup;
use App\Actions\Steps\Step;
use App\Enums\TaskStatus;
use App\OrgServer;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

/**
 * Points the app at the new server and sets it up there: the provider and application, a new
 * client id and secret, and the groups that may sign in. The setup waits (and is tried again)
 * until the new server has the groups from the LDAP source.
 *
 * Undone by PrepareSsoMove's rollback, which sees that the app points at the new server. What
 * was made on the new server stays.
 */
class SetupSso extends Step
{
    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;

        $app_instance->sso_server()->associate(OrgServer::findOrFail($task->getValue('target_org_server_id')));
        $app_instance->save();

        // One waiting from before the app was pointed here would have been for the old server
        ActionFacade::clear('application_sso_setup', $app_instance);
        $setup = ActionFacade::execute(new ApplicationSSOSetup($app_instance), $task, true);

        if (! $setup) {
            return ActionResult::fail(__('messages.sso_move.setup_not_scheduled'));
        }

        return ActionResult::wait(['sso_setup_task_id' => $setup->id]);
    }

    public function check(Task $task): ActionResult
    {
        if (! $setup_id = $task->getValue('sso_setup_task_id')) {
            return ActionResult::wait();
        }

        $setup = Task::find($setup_id);

        if ($setup?->status === TaskStatus::COMPLETE) {
            return ActionResult::done();
        }

        if (! $setup || $setup->status === TaskStatus::FAILED) {
            return ActionResult::fail($this->failure($task));
        }

        return ActionResult::wait();
    }

    public function timeoutMinutes(): ?int
    {
        return ApplicationSsoMove::SETUP_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return $this->failure($task);
    }

    private function failure(Task $task): string
    {
        return Task::find($task->getValue('sso_setup_task_id'))?->error_message ?: __('messages.sso_move.setup_failed');
    }
}
