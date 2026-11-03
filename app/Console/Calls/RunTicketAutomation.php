<?php

namespace App\Console\Calls;

use App\Services\Tickets\TicketAutomationRunner;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * Service level warnings, reminders, automatic assignment, automatic
 * resolving and closing, and retention.
 */
class RunTicketAutomation
{
    public function __invoke(): void
    {
        if (Toggle::inactive('tickets')) {
            return;
        }

        app(TicketAutomationRunner::class)->run();
    }
}
