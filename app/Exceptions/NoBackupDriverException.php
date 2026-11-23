<?php

namespace App\Exceptions;

use App\OrgServer;

/**
 * A backup was asked for on a server that has no backup driver, so there is
 * nothing to run it. Raised instead of quietly scheduling nothing.
 */
class NoBackupDriverException extends \RuntimeException
{
    public static function for(OrgServer $org_server): self
    {
        $server = $org_server->server;

        return new self(__('messages.exception.no_backup_driver_for', [
            'server' => $server?->name ?? '#'.$org_server->id,
            'type' => $server?->type ?? '?',
        ]));
    }
}
