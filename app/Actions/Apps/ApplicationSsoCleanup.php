<?php

namespace App\Actions\Apps;

use App\Actions\ActionResult;
use App\Actions\Apps\SsoMove\ClearSso;
use App\Actions\NativeAction;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\OrgServer;
use App\Support\Facades\ServerInterface;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Removes an app's provider and application from the SSO server it was moved away from,
 * once the move is trusted. The move leaves them there so that a failure could go back.
 *
 * The server removes by the provider id and slug in the app's settings, and those are now the
 * new server's: the ones the move saved are used instead, on a copy of the app that is never
 * saved. The new server's own entries are never asked about.
 */
class ApplicationSsoCleanup extends NativeAction
{
    public $slug = 'application_sso_cleanup';

    public function __construct(AppInstance $app_instance, Task $move)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->addCustomValue(['move_task_id' => $move->id]);

        $this->description = __('actions.removing_old_sso', [
            'app' => $app_instance->label,
            'server' => self::previousServer($move)?->server?->name,
        ]);
    }

    /**
     * The finished moves of the app that left an entry behind, which is still there and
     * is not on the server the app uses now.
     *
     * @return Collection<int, Task>
     */
    public static function pending(AppInstance $app_instance): Collection
    {
        $current = $app_instance->sso_server?->server_id;

        return Task::where('app_instance_id', $app_instance->id)
            ->where('action_slug', 'application_sso_move')
            ->where('status', TaskStatus::COMPLETE->value)
            ->orderBy('id')
            ->get()
            ->filter(function (Task $move) use ($current) {
                $previous = self::previousServer($move);

                // Nothing was set up on a server the app did not sign in through
                return ! $move->getValue('old_sso_removed_at')
                    && ! empty(Arr::get($move->getValue('sso_snapshot') ?? [], 'sso'))
                    && $previous
                    && $previous->server_id !== $current;
            })
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
            return ActionResult::fail(__('messages.sso_move.nothing_to_remove'));
        }

        ServerInterface::connect(
            self::previousServer($move),
            self::asItWasOn($app_instance, $move->getValue('sso_snapshot')),
        )->delete();

        $move->custom_values = array_merge($move->customValues(), ['old_sso_removed_at' => now()->toDateTimeString()]);
        $move->save();

        return ActionResult::done();
    }

    /**
     * A copy of the app, as it was before the move, for the old server to look its own ids up in.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private static function asItWasOn(AppInstance $app_instance, array $snapshot): AppInstance
    {
        $copy = $app_instance->replicate();
        $copy->id = $app_instance->id;
        $copy->settings = Arr::except($app_instance->settings ?? [], ClearSso::KEYS) + $snapshot;

        return $copy;
    }
}
