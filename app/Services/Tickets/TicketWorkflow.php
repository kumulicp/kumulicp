<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Ticket;

/**
 * Every status rule lives here. Controllers never set `status` directly.
 */
class TicketWorkflow
{
    /**
     * Staff may move a ticket to any other status (including reopening a closed
     * one). Members may close their ticket and reopen a resolved or closed one.
     */
    public function canChange(TicketStatus $from, TicketStatus $to, bool $asStaff): bool
    {
        if ($from === $to) {
            return false;
        }

        if ($asStaff) {
            return true;
        }

        return match ($to) {
            TicketStatus::CLOSED => true,
            TicketStatus::OPEN => ! $from->isOpen(),
            default => false,
        };
    }

    /**
     * The status a ticket moves to when a message is posted, or null to leave
     * it alone. Internal notes never change status.
     */
    public function statusAfterReply(Ticket $ticket, bool $byStaff, bool $internal): ?TicketStatus
    {
        if ($internal) {
            return null;
        }

        if ($byStaff) {
            return in_array($ticket->status, [TicketStatus::OPEN, TicketStatus::PENDING_SUPPORT], true)
                ? TicketStatus::PENDING_CUSTOMER
                : null;
        }

        return in_array($ticket->status, [TicketStatus::PENDING_CUSTOMER, TicketStatus::RESOLVED], true)
            ? TicketStatus::PENDING_SUPPORT
            : null;
    }

    /**
     * Move the ticket and keep the resolved/closed timestamps in step.
     */
    public function apply(Ticket $ticket, TicketStatus $to): void
    {
        $ticket->status = $to;

        $ticket->resolved_at = match ($to) {
            TicketStatus::RESOLVED => now(),
            TicketStatus::CLOSED => $ticket->resolved_at ?? now(),
            default => null,
        };
        $ticket->closed_at = $to === TicketStatus::CLOSED ? now() : null;
    }
}
