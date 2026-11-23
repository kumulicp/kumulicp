<?php

namespace App\Actions\Apps\ServerMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Steps\Step;
use App\AppInstance;
use App\OrgServer;
use App\Server;
use App\Services\OrgServerService;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

/**
 * Checks a move can go ahead and records where the app is coming from and going to,
 * before anything is touched, so the rollback (including one after a crash) always
 * knows the way back.
 *
 * Its rollback is the move's: it comes first of the steps, so by the time it is undone
 * every later step has been, and it looks at where the app actually points.
 *
 * A move says which of the app's servers it moves and what else stops it (blocker()).
 */
abstract class PrepareMove extends Step
{
    /**
     * Which of the app's servers is moved: 'database', 'web' or 'sso'.
     */
    abstract protected function type(): string;

    /**
     * Why the move can't go ahead, or null.
     */
    abstract protected function blocker(AppInstance $app_instance, Server $target_server): ?string;

    /**
     * What the failure message says about the target server after the rollback, if anything.
     */
    protected function rollbackNote(?OrgServer $target): ?string
    {
        return null;
    }

    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;
        $target_server = Server::findOrFail($task->getValue('target_server_id'));

        if ($blocker = $this->blocker($app_instance, $target_server)) {
            return ActionResult::fail($blocker);
        }

        $previous = $app_instance->{"{$this->type()}_server"};
        $target = OrgServerService::forServer($task->organization, $target_server)->get();

        return ActionResult::done([
            'previous_org_server_id' => $previous?->id,
            'target_org_server_id' => $target->id,
        ]);
    }

    /**
     * Back to the server the app was on before. If the app was already pointed at the new
     * one, it is updated again so the chart goes back to the old one. The new server's
     * copy is left alone: the app may still be running against it until that update lands,
     * and it is only ever a copy.
     */
    public function rollback(Task $task): ?string
    {
        $app_instance = $task->app_instance;
        $relation = "{$this->type()}_server";
        $column = "{$relation}_id";
        $previous = OrgServer::find($task->getValue('previous_org_server_id'));
        $target = OrgServer::find($task->getValue('target_org_server_id'));

        // Still pointing somewhere else than where it came from (an app that had no
        // such server goes back to having none)
        $moved = $previous
            ? $app_instance->$column !== $previous->id
            : ($target && $app_instance->$column === $target->id);

        if ($moved) {
            $previous ? $app_instance->$relation()->associate($previous) : $app_instance->$relation()->dissociate();
            $app_instance->status = 'updating';
            $app_instance->save();

            // The update that was trying to land the new server must
            // not block (or be mistaken for) the one that undoes it.
            ActionFacade::clear('application_update', $app_instance);
            ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);
        } else {
            $app_instance->status = 'active';
            $app_instance->save();
        }

        return $this->rollbackNote($target);
    }
}
