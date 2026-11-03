<?php

namespace App\Console\Calls;

use App\Services\Tickets\TicketService;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * Safety net for tickets whose assignee was deleted or deactivated without the
 * user-deleted event reaching us.
 */
class SweepTicketAssignees
{
    public function __invoke(): void
    {
        if (Toggle::inactive('tickets')) {
            return;
        }

        app(TicketService::class)->sweepRemovedAssignees();
    }
}
