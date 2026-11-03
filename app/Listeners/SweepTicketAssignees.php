<?php

namespace App\Listeners;

use App\Events\Users\UserDeleted;
use App\Services\Tickets\TicketService;

/**
 * When a user is removed from an organization, fix the tickets they were looking
 * after. (The events carry the organization, not the user, so this sweeps that
 * organization's tickets; the daily sweep is the backstop.)
 */
class SweepTicketAssignees
{
    public function __construct(private TicketService $tickets) {}

    public function handle(UserDeleted $event): void
    {
        $this->tickets->sweepRemovedAssignees($event->organization->id);
    }
}
