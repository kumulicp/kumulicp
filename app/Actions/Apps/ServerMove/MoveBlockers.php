<?php

namespace App\Actions\Apps\ServerMove;

use App\AppInstance;
use App\Organization;
use App\Server;
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
 * Each returns a reason (one of the constants), or null. for() runs all three, in
 * that order, and gives the message. A move that already words its own refusals
 * maps the reasons to them, as ApplicationDatabaseMigrate does.
 */
class MoveBlockers
{
    public const WRONG_ORGANIZATION = 'wrong_organization';

    public const APP_NOT_ACTIVE = 'app_not_active';

    public const HAS_CHILDREN = 'has_children';

    public const IS_CHILD = 'is_child';

    public const WRONG_TYPE = 'wrong_type';

    public const SERVER_INACTIVE = 'server_inactive';

    public const BACKUP_SERVER = 'backup_server';

    public const SAME_SERVER = 'same_server';

    public const TYPES = ['database', 'web', 'sso'];

    /**
     * The message for the first thing in the way of moving the app's $type server to
     * $target, or null if nothing is.
     */
    public static function for(
        AppInstance $app,
        string $type,
        Server $target,
        bool $require_active = true,
        ?Organization $organization = null,
    ): ?string {
        $reason = self::app($app, $type, $require_active, $organization)
            ?? self::target($type, $target)
            ?? self::same($app, $type, $target);

        return $reason ? self::message($reason, $type) : null;
    }

    /**
     * The app is in a state to be moved. $organization, when given, is the one the move
     * was asked for (the one in the URL), and the app has to be its own.
     */
    public static function app(AppInstance $app, string $type, bool $require_active = true, ?Organization $organization = null): ?string
    {
        self::guard($type);

        if ($organization && $app->organization_id !== $organization->id) {
            return self::WRONG_ORGANIZATION;
        }

        if ($require_active && $app->status !== 'active') {
            return self::APP_NOT_ACTIVE;
        }

        // A web move takes the release, and a shared app's children are part of it (their
        // own release is the parent's). Moving a child alone would move nothing.
        if ($type === 'web') {
            if ($app->children()->notDeactivated()->exists()) {
                return self::HAS_CHILDREN;
            }

            if (! $app->usesOwnResources()) {
                return self::IS_CHILD;
            }
        }

        return null;
    }

    /**
     * The server is one that can be used as the app's $type server: of that type, turned
     * on, and not a server that only holds backups.
     */
    public static function target(string $type, Server $target): ?string
    {
        self::guard($type);

        if ($target->type !== $type) {
            return self::WRONG_TYPE;
        }

        if ($target->status !== 'active') {
            return self::SERVER_INACTIVE;
        }

        if ($target->is_backup_server) {
            return self::BACKUP_SERVER;
        }

        return null;
    }

    /**
     * The app is not on that server already. For a database, a different server record for
     * the same host is just as much where the app is (restoring replaces what is there), so
     * the host is compared too. For the others it is not: a host is shared by, for example,
     * several clusters that one Rancher manages.
     */
    public static function same(AppInstance $app, string $type, Server $target): ?string
    {
        self::guard($type);

        $current = $app->{"{$type}_server"}?->server;

        if (! $current) {
            return null;
        }

        if ($current->id === $target->id || ($type === 'database' && $current->host && $current->host === $target->host)) {
            return self::SAME_SERVER;
        }

        return null;
    }

    public static function message(string $reason, string $type): string
    {
        return __("messages.server_move.{$reason}", ['type' => $type]);
    }

    private static function guard(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Apps have no '{$type}' server to move");
        }
    }
}
