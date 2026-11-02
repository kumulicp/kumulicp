<?php

namespace App\Console\Calls;

use App\Services\Tickets\AttachmentService;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * Deletes files that were uploaded to a ticket form but never sent.
 */
class PruneTicketUploads
{
    public function __invoke(): void
    {
        if (Toggle::inactive('tickets')) {
            return;
        }

        app(AttachmentService::class)->pruneOrphans();
    }
}
