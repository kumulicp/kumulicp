<?php

namespace App\Actions\Apps\SsoMove;

use App\Actions\Apps\ApplicationSsoMove;
use App\Actions\Apps\ServerMove\PrepareMove;
use App\AppInstance;
use App\OrgServer;
use App\Server;

/**
 * Checks the move can go ahead and records where the app is coming from and going to.
 * Its rollback is the move's (see PrepareMove): it comes first, so by then the saved
 * settings have been put back.
 */
class PrepareSsoMove extends PrepareMove
{
    protected function type(): string
    {
        return 'sso';
    }

    protected function blockers(AppInstance $app_instance, Server $target_server): array
    {
        return ApplicationSsoMove::blockers($app_instance, $target_server, require_active: false);
    }

    protected function rollbackNote(?OrgServer $target): ?string
    {
        return $target
            ? __('messages.sso_move.copy_left', ['server' => $target->server?->name])
            : null;
    }
}
