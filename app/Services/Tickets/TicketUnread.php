<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Ticket;
use App\TicketMessage;
use App\TicketRead;
use App\TicketSubscription;
use App\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which tickets have messages a person has not seen. A person's own messages
 * never count, customers only count messages they can see (public ones), and
 * staff count everything.
 */
class TicketUnread
{
    /**
     * Add `latest_other_message_id` and `read_message_id` to each ticket so a
     * list can show unread dots without a query per row.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function annotate(Builder $query, User $user, bool $staff): Builder
    {
        return $query->select('tickets.*')->addSelect([
            'latest_other_message_id' => $this->visibleMessages($staff, $user)->select('ticket_messages.id')
                ->whereColumn('ticket_messages.ticket_id', 'tickets.id')
                ->orderByDesc('ticket_messages.id')->limit(1),
            'read_message_id' => TicketRead::select('last_read_message_id')
                ->whereColumn('ticket_reads.ticket_id', 'tickets.id')
                ->where('ticket_reads.user_id', $user->id)
                ->limit(1),
        ]);
    }

    /**
     * Whether an annotated ticket has something unread.
     */
    public function isUnread(Ticket $ticket): bool
    {
        return (int) $ticket->getAttribute('latest_other_message_id') > (int) $ticket->getAttribute('read_message_id');
    }

    /**
     * Tickets with something unread that the person is involved in: the badge
     * in the navigation. Customers: tickets they opened, are assigned to, or
     * are CC'd on. Staff: tickets assigned to them or watched by them.
     */
    public function countFor(User $user, bool $staff): int
    {
        $watching = TicketSubscription::select('ticket_id')->where('user_id', $user->id)->where('watching', true);

        $query = Ticket::query()
            ->where('status', '!=', TicketStatus::CLOSED->value)
            ->where(function (Builder $query) use ($user, $staff, $watching) {
                if ($staff) {
                    $query->where('support_assignee_id', $user->id)->orWhereIn('id', $watching);
                } else {
                    $query->where('requester_id', $user->id)
                        ->orWhere('customer_assignee_id', $user->id)
                        ->orWhereIn('id', $watching);
                }
            });

        if (! $staff) {
            $query->where('organization_id', $user->organization_id);
        }

        return $this->annotate($query, $user, $staff)->get()
            ->filter(fn (Ticket $ticket) => $this->isUnread($ticket))
            ->count();
    }

    /**
     * Record that the person has now seen everything on the ticket.
     */
    public function markRead(Ticket $ticket, User $user, bool $staff): void
    {
        $latest = (int) $this->visibleMessages($staff, null)->where('ticket_messages.ticket_id', $ticket->id)->max('ticket_messages.id');

        $this->markReadUpTo($ticket, $user, $latest);
    }

    public function markReadUpTo(Ticket $ticket, User $user, int $messageId): void
    {
        $read = TicketRead::firstOrNew(['ticket_id' => $ticket->id, 'user_id' => $user->id]);

        // Never move backwards (an old tab must not mark newer messages unread)
        $read->last_read_message_id = max((int) $read->last_read_message_id, $messageId);
        $read->read_at = now();
        $read->save();
    }

    /**
     * Number of messages after $afterId that the person has not written and may see.
     */
    public function newMessagesSince(Ticket $ticket, User $user, bool $staff, int $afterId): int
    {
        return $this->visibleMessages($staff, $user)
            ->where('ticket_messages.ticket_id', $ticket->id)
            ->where('ticket_messages.id', '>', $afterId)
            ->count();
    }

    /**
     * Should a delayed email for $message still be sent to $recipient? Not if
     * they have read it by now, and not if the same person has written
     * something newer in the same conversation: that message's own email
     * covers it, so a burst of replies becomes one email.
     */
    public function shouldEmail(TicketMessage $message, User $recipient): bool
    {
        $read = (int) TicketRead::where('ticket_id', $message->ticket_id)->where('user_id', $recipient->id)->value('last_read_message_id');

        if ($read >= $message->id) {
            return false;
        }

        return ! TicketMessage::where('ticket_id', $message->ticket_id)
            ->where('id', '>', $message->id)
            ->where('author_id', $message->author_id)
            ->where('visibility', $message->visibility)
            ->exists();
    }

    /**
     * Messages the person is allowed to see, excluding their own when a user is given.
     *
     * @return Builder<TicketMessage>
     */
    private function visibleMessages(bool $staff, ?User $excluding): Builder
    {
        return TicketMessage::query()
            ->when(! $staff, fn (Builder $query) => $query->where('ticket_messages.visibility', TicketMessage::PUBLIC))
            ->when($excluding, fn (Builder $query) => $query->where(function (Builder $query) use ($excluding) {
                $query->whereNull('ticket_messages.author_id')->orWhere('ticket_messages.author_id', '!=', $excluding->id);
            }));
    }
}
