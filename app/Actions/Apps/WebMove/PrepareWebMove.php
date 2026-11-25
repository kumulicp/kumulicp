<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Apps\ServerMove\PrepareMove;
use App\AppInstance;
use App\OrgServer;
use App\Server;
use App\Task;

/**
 * Checks the move can go ahead and records where the app is coming from and going to. Its rollback is
 * the move's (see PrepareMove): the app is pointed at the old server again and updated.
 */
class PrepareWebMove extends PrepareMove
{
    protected function type(): string
    {
        return 'web';
    }

    protected function blockers(AppInstance $app_instance, Server $target_server): array
    {
        return ApplicationWebMove::blockers($app_instance, $target_server, require_active: false);
    }

    protected function taskBlockers(Task $task, AppInstance $app_instance, Server $target_server): array
    {
        // What it is to be moved to with it is what the new app server has to be able to reach
        return ApplicationWebMove::blockers(
            $app_instance,
            $target_server,
            require_active: false,
            database_server: Server::find($task->getValue('database_target_server_id')),
            sso_server: Server::find($task->getValue('sso_target_server_id')),
        );
    }

    protected function rollbackNote(?OrgServer $target): ?string
    {
        return $target
            ? __('messages.web_move.copy_left', ['server' => $target->server?->name])
            : null;
    }
}
