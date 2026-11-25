<?php

namespace App\Actions\Apps;

use App\Actions\Apps\ServerMove\ApplicationServerMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\Actions\Apps\SsoMove\ApplyUpdate;
use App\Actions\Apps\SsoMove\ClearSso;
use App\Actions\Apps\SsoMove\PrepareSsoMove;
use App\Actions\Apps\SsoMove\SetupSso;
use App\AppInstance;
use App\Server;
use App\Task;

/**
 * Moves an app's sign-in to another single sign-on server:
 *
 *  1. record where the app comes from and goes to
 *  2. save the app's sign-in settings (provider, application, client id and secret) and clear
 *     them: they belong to the old server, and a stale provider id would be looked up on the
 *     new one
 *  3. point the app at the new server and set it up there, which makes a provider and
 *     application and a new client id and secret
 *  4. standard app update, so the app is given the new server's address and secret
 *
 * Users and groups are not moved: they live in the control panel's directory, which the new
 * server reads through its LDAP source (the server test checks it is there).
 *
 * Nothing on the old server is touched, so any failure before the app is back up is rolled
 * back by restoring the saved settings, pointing the app at the old server again and running
 * another standard update. The old provider is left in place, and removed by a separate
 * action once the move is trusted. The new server's provider is left too, the way the
 * database migration leaves its copy.
 */
class ApplicationSsoMove extends ApplicationServerMove
{
    public $slug = 'application_sso_move';

    public static bool $long_running = true;

    // How long the new server has to be set up: it can take a while for the groups
    // to come through the LDAP source, which the setup waits for.
    public const SETUP_TIMEOUT_MINUTES = 30;

    // How long the app has, after being given the new server, to come back up.
    public const UPDATE_TIMEOUT_MINUTES = 20;

    public static function serverType(): string
    {
        return 'sso';
    }

    protected function describe(AppInstance $app_instance, Server $target_server): string
    {
        return __('actions.moving_app_sso_server', [
            'app' => $app_instance->label,
            'server' => $target_server->name,
        ]);
    }

    /**
     * Everything that stops this app's sign-in being moved to $target_server (nothing if
     * it can be), all at once.
     *
     * @return array<int, string>
     */
    public static function blockers(AppInstance $app_instance, Server $target_server, bool $require_active = true, bool $with_reach = true): array
    {
        $blockers = MoveBlockers::all($app_instance, 'sso', $target_server, $require_active, with_reach: $with_reach);

        // An earlier move left the app's provider there. Setting the app up would find that one
        // instead of making a new one, and so never make the client id and secret the app needs.
        $left = ApplicationSsoCleanup::pending($app_instance)
            ->contains(fn (Task $move) => ApplicationSsoCleanup::previousServer($move)?->server_id === $target_server->id);

        if ($left) {
            $blockers[] = __('messages.sso_move.old_entry_left', ['server' => $target_server->name]);
        }

        return $blockers;
    }

    public static function steps(): array
    {
        return [PrepareSsoMove::class, ClearSso::class, SetupSso::class, ApplyUpdate::class];
    }
}
