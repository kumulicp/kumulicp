<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Steps\Step;
use App\OrgServer;
use App\Task;

/**
 * A shared app's own plan says which servers it is deployed on: it is what the shared app's pages
 * and its version tests show, though a deployed instance takes its servers from itself, not from its plan.
 * Once the move is done, the plan is told where the app is now, so that those pages don't say it is
 * still where it was.
 *
 * Last, so that nothing has to undo it: a move that fails before it never changed the plan. It only
 * changes a server the plan still has as the one the app came from, so a plan that was pointed
 * somewhere else on purpose in the meantime is left alone. Other apps' plans are not the app's to
 * change: their servers are only what the apps on them start with.
 */
class UpdatePlanServers extends Step
{
    /**
     * The plan's server of each type, and the task's values for where the app was and went.
     */
    private const SERVERS = [
        'web' => ['previous_org_server_id', 'target_org_server_id'],
        'database' => ['database_previous_org_server_id', 'database_target_org_server_id'],
        'sso' => ['sso_previous_org_server_id', 'sso_target_org_server_id'],
    ];

    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance->fresh();
        $plan = $app_instance->plan;

        if (! $plan || ! $app_instance->isSharedApp()) {
            return ActionResult::done();
        }

        $changed = [];

        foreach (self::SERVERS as $type => [$from, $to]) {
            $previous = OrgServer::find($task->getValue($from))?->server_id;
            $target = OrgServer::find($task->getValue($to))?->server_id;
            $column = "{$type}_server_id";

            if ($previous && $target && (int) $plan->$column === (int) $previous) {
                $plan->$column = $target;
                $changed[] = $type;
            }
        }

        if ($changed) {
            $plan->save();
        }

        return ActionResult::done(['plan_servers_updated' => $changed]);
    }
}
