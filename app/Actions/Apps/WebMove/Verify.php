<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Steps\Step;
use App\OrgServer;
use App\Support\Facades\ServerInterface;
use App\Task;
use Illuminate\Support\Facades\Log;

/**
 * Asks the new server whether the app's release is running there. The install waits for its release
 * to be ready, but the app is only moved once the server says so itself. A release still starting is
 * waited for; one that is not running is a failure, and rolls the move back.
 */
class Verify extends Step
{
    public function run(Task $task): ActionResult
    {
        return $this->status($task);
    }

    public function check(Task $task): ActionResult
    {
        return $this->status($task);
    }

    private function status(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;
        $target = OrgServer::findOrFail($task->getValue('target_org_server_id'));

        $status = ServerInterface::connect($target, $app_instance)->checkStatus();

        if ($status['pending']) {
            return ActionResult::wait();
        }

        if (! $status['active']) {
            return ActionResult::fail(__('messages.web_move.not_running', ['message' => $status['message']]));
        }

        $app_instance->status = 'active';
        $app_instance->save();

        Log::info(__('messages.web_move.moved', [
            'app' => $app_instance->label,
            'server' => $target->server?->name,
        ]), ['organization_id' => $task->organization_id]);

        return ActionResult::done();
    }

    public function timeoutMinutes(): ?int
    {
        return ApplicationWebMove::VERIFY_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return __('messages.web_move.not_running', ['message' => __('messages.web_move.still_starting')]);
    }
}
