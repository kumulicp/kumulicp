<?php

namespace App\Actions\Apps;

use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\OrgServer;
use App\Support\Facades\ServerInterface;
use App\Task;
use Illuminate\Support\Collection;

/**
 * Removes an app's release from the server it was moved away from, once the move is trusted. The
 * move leaves it there, scaled to zero, so that a failure could go back.
 *
 * What it asks of the old server is to uninstall the release and its secrets and nothing more.
 * Deleting the app through that server's interface would also rewrite the app's redirect domains
 * on it, and install the app there again for the charts that are updated rather than removed.
 *
 * Removing a release can take its volume with it, depending on the storage class: the app is not
 * on that server any more, so that volume is the old copy, which is what is being removed.
 */
class ApplicationWebCleanup extends NativeAction
{
    public $slug = 'application_web_cleanup';

    public function __construct(AppInstance $app_instance, Task $move)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->addCustomValue(['move_task_id' => $move->id]);

        $this->description = __('actions.removing_old_web', [
            'app' => $app_instance->label,
            'server' => self::previousServer($move)?->server?->name,
        ]);
    }

    /**
     * The finished moves of the app that left a release behind, which is still there and is not on
     * the server the app runs on now.
     *
     * @return Collection<int, Task>
     */
    public static function pending(AppInstance $app_instance): Collection
    {
        $current = $app_instance->web_server?->server_id;

        return Task::where('app_instance_id', $app_instance->id)
            ->where('action_slug', 'application_web_move')
            ->where('status', TaskStatus::COMPLETE->value)
            ->orderBy('id')
            ->get()
            ->filter(fn (Task $move) => ! $move->getValue('old_release_removed_at')
                && ($previous = self::previousServer($move))
                && $previous->server_id !== $current)
            ->values();
    }

    public static function previousServer(Task $move): ?OrgServer
    {
        return OrgServer::find($move->getValue('previous_org_server_id'));
    }

    public static function handle(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;
        $move = self::pending($app_instance)->firstWhere('id', $task->getValue('move_task_id'));

        // Gone, or the app was moved back to it, since it was asked for
        if (! $move) {
            return ActionResult::fail(__('messages.web_move.nothing_to_remove'));
        }

        ServerInterface::connect(self::previousServer($move), $app_instance)->uninstallRelease();

        $move->custom_values = array_merge($move->customValues(), ['old_release_removed_at' => now()->toDateTimeString()]);
        $move->save();

        return ActionResult::done();
    }
}
