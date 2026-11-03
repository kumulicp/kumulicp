<?php

namespace App\Services\Tickets;

use App\Ticket;
use App\TicketAttachment;
use App\TicketDelivery;
use App\TicketEvent;
use App\TicketExternalLink;
use App\TicketMessage;
use App\TicketMessageRevision;
use App\TicketRead;
use App\TicketSubscription;
use App\TicketTimeEntry;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes tickets and everything that hangs off them, including
 * the uploaded files. This is the only place that does so: retention, deleting
 * an organization and the "delete for ever" action all come here.
 */
class TicketPurger
{
    public function purge(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket) {
            // One by one so each file is deleted with its row
            TicketAttachment::where('ticket_id', $ticket->id)->each(fn (TicketAttachment $attachment) => $attachment->delete());

            $messageIds = TicketMessage::withTrashed()->where('ticket_id', $ticket->id)->pluck('id');
            TicketMessageRevision::whereIn('ticket_message_id', $messageIds)->delete();
            TicketMessage::withTrashed()->where('ticket_id', $ticket->id)->forceDelete();

            TicketEvent::where('ticket_id', $ticket->id)->delete();
            TicketRead::where('ticket_id', $ticket->id)->delete();
            TicketSubscription::where('ticket_id', $ticket->id)->delete();
            TicketTimeEntry::where('ticket_id', $ticket->id)->delete();
            TicketExternalLink::where('ticket_id', $ticket->id)->delete();
            TicketDelivery::where('ticket_id', $ticket->id)->delete();
            DB::table('ticket_ticket_tag')->where('ticket_id', $ticket->id)->delete();

            // Tickets merged into this one lose their pointer rather than point at nothing
            Ticket::withTrashed()->where('merged_into_id', $ticket->id)->update(['merged_into_id' => null]);

            $ticket->forceDelete();
        });
    }

    /**
     * Everything an organization ever raised (used when the organization is deleted).
     *
     * @return int number of tickets removed
     */
    public function purgeOrganization(int $organizationId): int
    {
        $count = 0;

        Ticket::withTrashed()->where('organization_id', $organizationId)->each(function (Ticket $ticket) use (&$count) {
            $this->purge($ticket);
            $count++;
        });

        return $count;
    }

    /**
     * Closed tickets older than the retention period.
     *
     * @return int number of tickets removed
     */
    public function purgeClosedOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $count = 0;

        Ticket::withTrashed()
            ->where('status', 'closed')
            ->where('closed_at', '<', now()->subDays($days))
            ->each(function (Ticket $ticket) use (&$count) {
                $this->purge($ticket);
                $count++;
            });

        return $count;
    }
}
