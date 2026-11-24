<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Steps\Step;
use App\OrgServer;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

/**
 * Stops the app on the old server, so that its volume is not written to while it is moved: the
 * app's chart is told to scale its release to zero, and the release updated. The release stays
 * where it is, to be scaled up again if the move is given up.
 *
 * Undone by telling the chart to stop doing that. If the app was never pointed at the new server
 * nothing else would put the release back, so it is updated here; once it was, the move's own
 * rollback updates it.
 */
class Quiesce extends Step
{
    use WaitsForUpdate;

    protected function updateKey(): string
    {
        return 'quiesce_update_task_id';
    }

    protected function failedMessage(): string
    {
        return __('messages.web_move.quiesce_failed');
    }

    public function run(Task $task): ActionResult
    {
        $task->app_instance->updateSetting(ApplicationWebMove::QUIESCE_SETTING, true);

        return $this->startUpdate($task, ['release']);
    }

    public function check(Task $task): ActionResult
    {
        return $this->updateResult($task);
    }

    public function rollback(Task $task): ?string
    {
        $app_instance = $task->app_instance;
        $app_instance->updateSetting(ApplicationWebMove::QUIESCE_SETTING, false);

        $previous = OrgServer::find($task->getValue('previous_org_server_id'));

        if ($previous && $app_instance->web_server_id === $previous->id) {
            ActionFacade::clear('application_update', $app_instance);
            ActionFacade::execute(new ApplicationUpdate($app_instance, ['release']), $task, true);
        }

        return null;
    }

    public function timeoutMinutes(): ?int
    {
        return ApplicationWebMove::QUIESCE_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return $this->failure($task);
    }
}
