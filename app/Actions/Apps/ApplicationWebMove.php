<?php

namespace App\Actions\Apps;

use App\Actions\Apps\ServerMove\ApplicationServerMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\Actions\Apps\WebMove\AwaitVolume;
use App\Actions\Apps\WebMove\PrepareWebMove;
use App\Actions\Apps\WebMove\Quiesce;
use App\Actions\Apps\WebMove\RefreshDns;
use App\Actions\Apps\WebMove\SwitchServer;
use App\Actions\Apps\WebMove\Verify;
use App\AppInstance;
use App\Server;
use App\Support\ServerReach;

/**
 * Moves an app to another web server (another cluster), with its volume (see
 * docs/server-transitions-plan.md, "Web (app) server"):
 *
 *  1. record where the app comes from and goes to
 *  2. stop the app on the old server, so that the volume is consistent: the release is scaled to
 *     zero and left there
 *  3. wait for an administrator to restore the volume on the new cluster, and to say what it is
 *     called (or that the app is to start with a new one): that is done by hand, on the cluster
 *  4. give the chart that volume, point the app at the new server and run the standard update,
 *     which installs the release there
 *  5. check the release is running on the new server
 *  6. refresh the DNS records the app's domains were given the old server's address in
 *
 * The old release is only scaled down, never removed, so any failure up to the app being up on
 * the new server is rolled back by putting the settings back, pointing the app at the old
 * server and running another standard update, which scales it up again. Removing the old
 * release is a separate action once the move is trusted. What the new server was given is left,
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

    protected function describe(AppInstance $app_instance, Server $target_server): string
    {
        return __('actions.moving_app_web_server', [
            'app' => $app_instance->label,
            'server' => $target_server->name,
        ]);
    }

    /**
     * Everything that stops this app being moved to $target_server (nothing if it can be), all at once.
     *
     * @return array<int, string>
     */
    public static function blockers(AppInstance $app_instance, Server $target_server, bool $require_active = true, bool $with_reach = true): array
    {
        $blockers = MoveBlockers::all($app_instance, 'web', $target_server, $require_active, with_reach: $with_reach);

        // Rancher and plain Kubernetes servers are given different values, so an app can't be taken from one
        // to the other by a move
        $current = $app_instance->web_server?->server;

        if ($current && $current->interface !== $target_server->interface) {
            $blockers[] = __('messages.web_move.other_driver', ['server' => $current->name, 'driver' => $current->interface]);
        }

        return $blockers;
    }

    /**
     * What has to be true of the servers the app uses for the new app server to be able to reach them,
     * and what to do if it is not: a database is always on a network of its own, so is moved to one both
     * app servers can reach; the SSO server is only if the new app server can't reach it already, which
     * is not the case for one that is on the internet. Only the servers the app has are listed.
     *
     * @return array<int, array{type: string, server: string, reachable: bool, bridges: array<int, array{value: int, text: string}>}>
     */
    public static function requirements(AppInstance $app_instance, Server $target_server): array
    {
        $current = $app_instance->web_server?->server;
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
                // The ones to move it to first: the app is on the old app server until it is moved, so
                // they have to be reachable from that one too
                'bridges' => $reachable || ! $current ? [] : ServerReach::reachableFromAll($type, [$current, $target_server])
                    ->map(fn (Server $server) => ['value' => $server->id, 'text' => $server->name])->values()->all(),
            ];
        }

        return $requirements;
    }

    public static function steps(): array
    {
        return [PrepareWebMove::class, Quiesce::class, AwaitVolume::class, SwitchServer::class, Verify::class, RefreshDns::class];
    }
}
