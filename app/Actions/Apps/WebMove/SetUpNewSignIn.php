<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationSSOSetup;
use App\Actions\Apps\SsoMove\ClearSso;
use App\Actions\Apps\SsoMove\SetupSso;
use App\OrgServer;
use App\Server;
use App\Services\OrgServerService;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Arr;

/**
 * Sets the app up on the new sign-in server (when the move was started with one,
 * `sso_target_server_id`): its provider and application there, a new client id and secret, and the
 * groups that may sign in. The app's own sign-in settings are the old server's, so they are saved
 * on the task first and cleared; the old server's entries are never touched.
 *
 * Nothing is installed from it yet: the release is only updated by SwitchServer, on the new app
 * server, where it is given the new address and secret. The old release was last updated by the
 * quiesce, before any of this, and keeps what it was given.
 *
 * Undone by putting the saved settings and the old server back. What was made on the new server
 * stays, the way a sign-in move leaves its own.
 */
class SetUpNewSignIn extends SetupSso
{
    public function run(Task $task): ActionResult
    {
        if (! $target_server_id = $task->getValue('sso_target_server_id')) {
            return ActionResult::done();
        }

        $app_instance = $task->app_instance;
        $settings = $app_instance->settings ?? [];
        $target = OrgServerService::forServer($task->organization, Server::findOrFail($target_server_id))->get();

        // Saved before anything is removed, so that a crash in between loses nothing
        $task->custom_values = array_merge($task->customValues(), [
            'sso_snapshot' => Arr::only($settings, ClearSso::KEYS),
            'sso_previous_org_server_id' => $app_instance->sso_server_id,
            'sso_target_org_server_id' => $target->id,
        ]);
        $task->save();

        $app_instance->settings = Arr::except($settings, ClearSso::KEYS);
        $app_instance->sso_server()->associate($target);
        $app_instance->save();

        // One waiting from before the app was pointed here would have been for the old server
        ActionFacade::clear('application_sso_setup', $app_instance);
        $setup = ActionFacade::execute(new ApplicationSSOSetup($app_instance), $task, true);

        if (! $setup) {
            return ActionResult::fail(__('messages.sso_move.setup_not_scheduled'));
        }

        return ActionResult::wait(['sso_setup_task_id' => $setup->id]);
    }

    public function rollback(Task $task): ?string
    {
        $snapshot = $task->getValue('sso_snapshot');

        // Never got as far as saving them, so nothing was changed
        if (! is_array($snapshot)) {
            return null;
        }

        $app_instance = $task->app_instance;
        $previous = OrgServer::find($task->getValue('sso_previous_org_server_id'));

        // What the new server added goes, what the old one had comes back
        $app_instance->settings = Arr::except($app_instance->settings ?? [], ClearSso::KEYS) + $snapshot;
        $previous ? $app_instance->sso_server()->associate($previous) : $app_instance->sso_server()->dissociate();
        $app_instance->save();

        return null;
    }
}
