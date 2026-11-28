<?php

namespace App\Actions\Apps;

use App\Actions\Apps\ServerMove\ApplicationServerMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\Actions\Apps\WebMove\AwaitVolume;
use App\Actions\Apps\WebMove\BackupOldDatabase;
use App\Actions\Apps\WebMove\PrepareWebMove;
use App\Actions\Apps\WebMove\Quiesce;
use App\Actions\Apps\WebMove\RefreshDns;
use App\Actions\Apps\WebMove\RestoreNewDatabase;
use App\Actions\Apps\WebMove\RunSettingsJob;
use App\Actions\Apps\WebMove\SetUpNewSignIn;
use App\Actions\Apps\WebMove\SwitchServer;
use App\Actions\Apps\WebMove\UpdatePlanServers;
use App\Actions\Apps\WebMove\Verify;
use App\AppInstance;
use App\Server;
use App\Support\ServerReach;
use App\Task;
use Illuminate\Support\Arr;

/**
 * Moves an app to another web server (another cluster), with its volume (see
 * docs/server-transitions-plan.md, "Web (app) server"):
 *
 *  1. record where the app comes from and goes to
 *  2. stop the app on the old server, so that the volume (and the database) is consistent: the
 *     release is scaled to zero and left there
 *  3. if the move was started with a new database server: back the database up and restore it there
 *  4. if it was started with a new sign-in server: set the app up there
 *  5. wait for an administrator to restore the volume on the new cluster, and to say what it is
 *     called (or that the app is to start with a new one): that is done by hand, on the cluster
 *  6. give the chart that volume, point the app at the new server (and the new database) and update
 *     the release, which installs it there and is done when it is running
 *  7. check the release is running on the new server
 *  8. run the app's settings job against it (not before: it runs the app's own commands, which need
 *     the release to be installed)
 *  9. refresh the DNS records the app's domains were given the old server's address in, and those of the
 *     apps that run in its release (the apps shared from a shared app, an addon)
 * 10. for a shared app, tell its own plan where it is now
 *
 * The servers the app uses are changed here only when the new app server can't reach them, or the
 * administrator wants others, and then only as part of the move: the old release is never updated
 * after it is scaled down, so it is still what it was, running against the old database and sign-in
 * server, and any failure up to the app being up on the new server is rolled back by putting the
 * settings and servers back, pointing the app at the old server and running another standard update,
 * which scales the old release up again. (Changing only the database or the sign-in server of an app
 * that stays where it is is a migration of its own, that does update the app.) Removing the old
 * release is a separate action once the move is trusted. What the new servers were given is left,
 * the way the other moves leave theirs.
 */
class ApplicationWebMove extends ApplicationServerMove
{
    public $slug = 'application_web_move';

    public static bool $long_running = true;

    // The app is stopped by an update that scales its release to zero
    public const QUIESCE_TIMEOUT_MINUTES = 20;

    // Installing on a new cluster pulls the images, so it can take a while
    public const UPDATE_TIMEOUT_MINUTES = 30;

    // How long the release has, once installed, to be running
    public const VERIFY_TIMEOUT_MINUTES = 15;

    // How long the app's settings job has, once the release is running
    public const SETTINGS_TIMEOUT_MINUTES = 20;

    // The instance setting that has the app's chart scale its release to zero
    public const QUIESCE_SETTING = 'quiesced_for_move';

    public static function serverType(): string
    {
        return 'web';
    }

    protected static function messageGroup(): string
    {
        return 'web_move';
    }

    /**
     * @param  array<string, mixed>  $storage  What the app's chart is to be given for its volume
     *                                         (StorageHandover): chosen here, so that the move does
     *                                         not have to stop and ask for it
     */
    public function __construct(AppInstance $app_instance, Server $target_server, array $storage, ?Server $database_server = null, ?Server $sso_server = null)
    {
        parent::__construct($app_instance, $target_server);

        $this->addCustomValue(['storage' => $storage]);

        // The apps that run in its release (the apps shared from a shared app, an addon), as they are when the
        // move is asked for: their domains are refreshed after the move too
        $children = $app_instance->children()->notDeactivated()->pluck('id')->all();

        if ($children) {
            $this->addCustomValue(['child_ids' => $children]);
        }

        if ($database_server) {
            $this->addCustomValue(['database_target_server_id' => $database_server->id]);
        }

        if ($sso_server) {
            $this->addCustomValue(['sso_target_server_id' => $sso_server->id]);
        }
    }

    // Starts over where it was going, with what the app was to be given
    public static function reset(Task $task): void
    {
        $task->custom_values = Arr::only($task->customValues(), ['target_server_id', 'storage', 'database_target_server_id', 'sso_target_server_id', 'child_ids']);

        static::begin($task);
    }

    protected function describe(AppInstance $app_instance, Server $target_server): string
    {
        return __('actions.moving_app_web_server', [
            'app' => $app_instance->label,
            'server' => $target_server->name,
        ]);
    }

    /**
     * Everything that stops this app being moved to $target_server (nothing if it can be), all at once.
     * $database_server and $sso_server are the servers it would be moved to with it: those, not the
     * ones it has, are what the new app server has to be able to reach, and they only have to be
     * reachable from there (the old release is not touched).
     *
     * @return array<int, string>
     */
    public static function blockers(AppInstance $app_instance, Server $target_server, bool $require_active = true, bool $with_reach = true, ?Server $database_server = null, ?Server $sso_server = null): array
    {
        $blockers = MoveBlockers::all($app_instance, 'web', $target_server, $require_active, with_reach: false);

        if ($with_reach) {
            foreach (['database' => [$database_server, MoveBlockers::CANNOT_REACH_DATABASE], 'sso' => [$sso_server, MoveBlockers::CANNOT_REACH_SSO]] as $type => [$chosen, $reason]) {
                $used = $chosen ?? $app_instance->{"{$type}_server"}?->server;

                if ($used && ! ServerReach::reaches($target_server, $used)) {
                    $blockers[] = MoveBlockers::message($reason, 'web', ['server' => $used->name]);
                }
            }
        }

        // What the app is to be moved to with it has to be a server it can be moved to: not the one it has,
        // not one without a way to copy to it. Not that the app can reach it from where it is
        if ($database_server) {
            $blockers = [...$blockers, ...ApplicationDatabaseMigrate::blockers($app_instance, $database_server, $require_active, with_reach: false)];
        }

        if ($sso_server) {
            $blockers = [...$blockers, ...ApplicationSsoMove::blockers($app_instance, $sso_server, $require_active, with_reach: false)];
        }

        // Rancher and plain Kubernetes servers are given different values, so an app can't be taken from one
        // to the other by a move
        $current = $app_instance->web_server?->server;

        if ($current && $current->interface !== $target_server->interface) {
            $blockers[] = __('messages.web_move.other_driver', ['server' => $current->name, 'driver' => $current->interface]);
        }

        return array_values(array_unique($blockers));
    }

    /**
     * What has to be true of the servers the app uses for the new app server to be able to reach them,
     * and what to do if it is not: a database is always on a network of its own, so the app is moved to
     * one the new app server can reach, with the app; the sign-in server is only if the new app server
     * can't reach it already, which is not the case for one that is on the internet. Only the servers
     * the app has are listed. The ones to choose from only have to be reachable from the new app
     * server: the old one is not touched, and does not have to reach them.
     *
     * @return array<int, array{type: string, server: string, reachable: bool, bridges: array<int, array{value: int, text: string}>}>
     */
    public static function requirements(AppInstance $app_instance, Server $target_server): array
    {
        $requirements = [];

        foreach (['database', 'sso'] as $type) {
            $used = $app_instance->{"{$type}_server"}?->server;

            if (! $used) {
                continue;
            }

            $reachable = ServerReach::reaches($target_server, $used);

            $requirements[] = [
                'type' => $type,
                'server' => $used->name,
                'reachable' => $reachable,
                'bridges' => $reachable ? [] : ServerReach::reachableFromAll($type, [$target_server])
                    ->map(fn (Server $server) => ['value' => $server->id, 'text' => $server->name])->values()->all(),
            ];
        }

        return $requirements;
    }

    public static function steps(): array
    {
        return [PrepareWebMove::class, Quiesce::class, BackupOldDatabase::class, RestoreNewDatabase::class, SetUpNewSignIn::class, AwaitVolume::class, SwitchServer::class, Verify::class, RunSettingsJob::class, RefreshDns::class, UpdatePlanServers::class];
    }
}
