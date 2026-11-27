<?php

namespace App\Actions\Apps\ServerMove;

use App\AppInstance;
use App\Enums\TaskStatus;
use App\Organization;
use App\Server;
use App\Support\ServerReach;
use App\Support\SharedAppContext;
use App\Task;
use InvalidArgumentException;

/**
 * Whether an app's server of one type ('database', 'web' or 'sso') can be moved to
 * another `Server`: the checks every move makes before it touches anything
 * (docs/server-transitions-plan.md, "Rules that apply to every move").
 *
 * The checks are in three groups, which a move can use on their own where it
 * has to check something of its own in between:
 *
 *  - app(): is the app in a state to be moved
 *  - target(): is the server one that can take the app
 *  - same(): is it not where the app already is
 *
 * Each returns every reason that applies (constants, in order), not just the first, so
 * that an administrator sees everything in the way at once instead of fixing one thing
 * to find the next. all() runs all three and gives the messages; for() is the first of
 * them. A move that already words its own refusals maps the reasons to them, as
 * ApplicationDatabaseMigrate does.
 */
class MoveBlockers
{
    public const WRONG_ORGANIZATION = 'wrong_organization';

    public const APP_NOT_ACTIVE = 'app_not_active';

    public const HAS_CHILDREN = 'has_children';

    public const IS_CHILD = 'is_child';

    // An addon (a CiviCRM in a WordPress) runs in its parent's release and signs in as its parent does
    public const ADDON_CHILD = 'addon_child';

    // A shared app that nothing is deployed for: still to be set up, or run outside Kumuli CP
    public const SHARED_NOT_ACTIVE = 'shared_not_active';

    // Something is being done to a child of the app, which the move would run its release under
    public const CHILD_TASKS_OPEN = 'child_tasks_open';

    // A child with a database of its own (ERPNext, a CiviCRM) is not looked after by a web move yet
    public const CHILD_DATABASES_UNSUPPORTED = 'child_databases_unsupported';

    public const NO_SSO_SUPPORT = 'no_sso_support';

    public const WRONG_TYPE = 'wrong_type';

    public const SERVER_INACTIVE = 'server_inactive';

    public const BACKUP_SERVER = 'backup_server';

    public const SAME_SERVER = 'same_server';

    // A database or SSO move: the app has no app server to say whether it can reach the target from
    public const NO_APP_SERVER = 'no_app_server';

    public const NOT_REACHABLE = 'not_reachable';

    // An app server move: the new one can't reach what the app uses
    public const CANNOT_REACH_DATABASE = 'cannot_reach_database';

    public const CANNOT_REACH_SSO = 'cannot_reach_sso';

    public const TYPES = ['database', 'web', 'sso'];

    /**
     * The messages for everything in the way of moving the app's $type server to $target
     * (none if nothing is). Without $with_reach, not the servers being unable to reach each other:
     * that is the one thing that can be made right in steps before the move.
     *
     * @return array<int, string>
     */
    public static function all(
        AppInstance $app,
        string $type,
        Server $target,
        bool $require_active = true,
        ?Organization $organization = null,
        bool $with_reach = true,
    ): array {
        return collect([
            ...self::app($app, $type, $require_active, $organization),
            ...self::target($type, $target),
            ...($with_reach ? self::reach($app, $type, $target) : []),
            ...self::same($app, $type, $target),
        ])->map(fn (string $reason) => self::message($reason, $type, self::reachParams($app, $reason)))->unique()->values()->all();
    }

    /**
     * The message for the first thing in the way, or null if nothing is.
     */
    public static function for(
        AppInstance $app,
        string $type,
        Server $target,
        bool $require_active = true,
        ?Organization $organization = null,
    ): ?string {
        return self::all($app, $type, $target, $require_active, $organization)[0] ?? null;
    }

    /**
     * Messages as one text, for where there is room for only one (a flash message, a
     * task's error).
     *
     * @param  array<int, string>  $messages
     */
    public static function summary(array $messages): string
    {
        return implode('. ', $messages).'.';
    }

    /**
     * The app is in a state to be moved. $organization, when given, is the one the move
     * was asked for (the one in the URL), and the app has to be its own.
     *
     * @return array<int, string>
     */
    public static function app(AppInstance $app, string $type, bool $require_active = true, ?Organization $organization = null): array
    {
        self::guard($type);

        $reasons = [];

        if ($organization && $app->organization_id !== $organization->id) {
            $reasons[] = self::WRONG_ORGANIZATION;
        }

        if ($require_active && $app->status !== 'active') {
            $reasons[] = self::APP_NOT_ACTIVE;
        }

        // A shared app that is not deployed by Kumuli CP has nothing to move
        if ($app->isSharedApp() && SharedAppContext::state($app) !== SharedAppContext::ACTIVE) {
            $reasons[] = self::SHARED_NOT_ACTIVE;
        }

        // A web move takes the release, and a shared app's children are part of it (their
        // own release is the parent's). Moving a child alone would move nothing.
        if ($type === 'web' && $app->children()->notDeactivated()->exists()) {
            $reasons = [...$reasons, ...self::childrenReasons($app)];
        }

        // An app that can't sign people in through an SSO server has nothing to move
        if ($type === 'sso' && ! $app->supportsSso()) {
            $reasons[] = self::NO_SSO_SUPPORT;
        }

        // The same child has no sign-in of its own either: it signs in as its parent does
        if (in_array($type, ['web', 'sso'], true) && ! $app->usesOwnResources()) {
            $reasons[] = self::IS_CHILD;
        }

        // An addon has a database of its own, but is installed in its parent's release and signs in through
        // its parent's server, so those two are moved with the parent
        if (in_array($type, ['web', 'sso'], true) && $app->isAddonChild()) {
            $reasons[] = self::ADDON_CHILD;
        }

        return $reasons;
    }

    /**
     * What stands in the way of a web move for the children of the app. Only the children that are
     * part of its release go with it: the apps shared from a shared app, and addons (a CiviCRM in a
     * WordPress). Any other app's children, which nothing here knows how to carry, still stop it.
     *
     * A child with a database of its own is not yet looked after by the move (docs/shared-app-move-plan.md,
     * phase 3), and neither is the database or sign-in server it would be moved to, so it is refused
     * rather than moved with a database pointing at the old place.
     *
     * @return array<int, string>
     */
    private static function childrenReasons(AppInstance $app): array
    {
        $children = $app->children()->notDeactivated()->with('plan')->get();

        if ($children->contains(fn (AppInstance $child) => ! $child->sharedPlanParent() && ! $child->isAddonChild())) {
            return [self::HAS_CHILDREN];
        }

        $reasons = [];

        if ($children->contains(fn (AppInstance $child) => $child->usesOwnResources() && ($child->databasename || $child->database_server_id))) {
            $reasons[] = self::CHILD_DATABASES_UNSUPPORTED;
        }

        // Their updates and jobs are run on the release the move is stopping and starting again
        if (Task::whereIn('app_instance_id', $children->modelKeys())->whereIn('status', TaskStatus::openValues())->exists()) {
            $reasons[] = self::CHILD_TASKS_OPEN;
        }

        return $reasons;
    }

    /**
     * The server is one that can be used as the app's $type server: of that type, turned
     * on, and not a server that only holds backups.
     *
     * @return array<int, string>
     */
    public static function target(string $type, Server $target): array
    {
        self::guard($type);

        $reasons = [];

        if ($target->type !== $type) {
            $reasons[] = self::WRONG_TYPE;
        }

        if ($target->status !== 'active') {
            $reasons[] = self::SERVER_INACTIVE;
        }

        if ($target->is_backup_server) {
            $reasons[] = self::BACKUP_SERVER;
        }

        return $reasons;
    }

    /**
     * The servers can reach each other, which is said on the servers (see ServerReach). A database or SSO
     * server has to be one the app's app server can reach, which is why the app has to have one. And the
     * other way: a new app server has to reach the database and SSO server the app already uses, so
     * those are moved first, to one both app servers can reach.
     *
     * @return array<int, string>
     */
    public static function reach(AppInstance $app, string $type, Server $target): array
    {
        self::guard($type);

        if ($type === 'web') {
            $reasons = [];

            foreach (['database' => self::CANNOT_REACH_DATABASE, 'sso' => self::CANNOT_REACH_SSO] as $used => $reason) {
                $server = $app->{"{$used}_server"}?->server;

                if ($server && ! ServerReach::reaches($target, $server)) {
                    $reasons[] = $reason;
                }
            }

            return $reasons;
        }

        if (! in_array($type, ['database', 'sso'], true)) {
            return [];
        }

        $app_server = $app->web_server?->server;

        if (! $app_server) {
            return [self::NO_APP_SERVER];
        }

        return ServerReach::reaches($app_server, $target) ? [] : [self::NOT_REACHABLE];
    }

    /**
     * What the message of a reason has in it besides the type: the server it is about.
     *
     * @return array<string, mixed>
     */
    public static function reachParams(AppInstance $app, string $reason): array
    {
        return match ($reason) {
            self::NOT_REACHABLE => ['app_server' => $app->web_server?->server?->name],
            self::CANNOT_REACH_DATABASE => ['server' => $app->database_server?->server?->name],
            self::CANNOT_REACH_SSO => ['server' => $app->sso_server?->server?->name],
            default => [],
        };
    }

    /**
     * The app is not on that server already. For a database, a different server record for
     * the same host is just as much where the app is (restoring replaces what is there), so
     * the host is compared too. For the others it is not: a host is shared by, for example,
     * several clusters that one Rancher manages.
     *
     * @return array<int, string>
     */
    public static function same(AppInstance $app, string $type, Server $target): array
    {
        self::guard($type);

        $current = $app->{"{$type}_server"}?->server;

        if (! $current) {
            return [];
        }

        if ($current->id === $target->id || ($type === 'database' && $current->host && $current->host === $target->host)) {
            return [self::SAME_SERVER];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function message(string $reason, string $type, array $params = []): string
    {
        return __("messages.server_move.{$reason}", ['type' => $type] + $params);
    }

    private static function guard(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Apps have no '{$type}' server to move");
        }
    }
}
