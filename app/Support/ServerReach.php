<?php

namespace App\Support;

use App\Server;
use Illuminate\Support\Collection;

/**
 * Whether an app running on an app server (a web server) can connect to a database or SSO server.
 *
 * There is no telling that from the servers' own details (an address is whatever the administrator
 * typed), so it is said on the server: the app servers that can reach it. A database server is
 * reached over a network the app servers share with it, so only the ones it is assigned to can. An
 * SSO server is usually on the internet, so one assigned to none is reachable from all of them, and
 * one assigned to some only from those.
 */
class ServerReach
{
    public static function reaches(Server $app_server, Server $server): bool
    {
        $assigned = $server->app_servers->contains('id', $app_server->id);

        return match ($server->type) {
            'database' => $assigned,
            'sso' => $assigned || $server->app_servers->isEmpty(),
            default => true,
        };
    }

    /**
     * The servers of $type that every one of the app servers can reach, to move from one to the other
     * by way of: both have to be able to use it.
     *
     * @param  iterable<int, Server>  $app_servers
     * @return Collection<int, Server>
     */
    public static function reachableFromAll(string $type, iterable $app_servers): Collection
    {
        $app_servers = collect($app_servers);

        return Server::with('app_servers')
            ->where('type', $type)
            ->where('is_backup_server', false)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $server) => $app_servers->every(fn (Server $app_server) => self::reaches($app_server, $server)))
            ->values();
    }
}
