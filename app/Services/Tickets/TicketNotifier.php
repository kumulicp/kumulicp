<?php

namespace App\Services\Tickets;

use App\Channels\PanelChannel;
use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use App\Support\Facades\Settings;
use App\Ticket;
use App\TicketMessage;
use App\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Decides who is told about what. The rule that matters: internal notes only
 * ever reach staff.
 */
class TicketNotifier
{
    public function __construct(private TicketStaff $staff) {}

    public function created(Ticket $ticket): void
    {
        $author = $ticket->requester;

        // Confirmation to the people on the customer side, including whoever opened it
        $this->send($this->customerSide($ticket, null), new TicketActivity($ticket, 'created', false, ['name' => $author?->name ?? '']));

        $this->toSupport($ticket, 'created', ['name' => $author?->name ?? '']);

        // Rollout copy to the legacy support address
        $support_email = Settings::get('support_email');
        if ($support_email && config('tickets.copy_support_email')) {
            Notification::route('mail', $support_email)->notify(new TicketActivity($ticket, 'created', true, ['name' => $author?->name ?? '']));
        }
    }

    public function messageAdded(Ticket $ticket, TicketMessage $message): void
    {
        $author = $message->author;
        $context = [
            'name' => $author?->name ?? '',
            'excerpt' => Str::limit($message->body_text, 300),
        ];

        if ($message->isInternal()) {
            if ($ticket->support_assignee_id && $ticket->support_assignee_id !== $message->author_id) {
                $this->send($this->only($ticket->supportAssignee), new TicketActivity($ticket, 'note', true, $context));
            }

            return;
        }

        if ($message->isStaff()) {
            $this->send($this->customerSide($ticket, $author), new TicketActivity($ticket, 'reply', false, $context));

            return;
        }

        // A customer replied: tell support, and any colleagues on the customer side
        $this->toSupport($ticket, 'reply', $context, $author);
        $this->send($this->customerSide($ticket, $author), new TicketActivity($ticket, 'reply', false, $context));
    }

    public function statusChanged(Ticket $ticket, TicketStatus $from, ?User $actor): void
    {
        $context = ['status' => $ticket->status->label(), 'name' => $actor?->name ?? ''];

        $this->send($this->customerSide($ticket, $actor), new TicketActivity($ticket, 'status', false, $context));

        if ($ticket->support_assignee_id && $ticket->support_assignee_id !== $actor?->id) {
            $this->send($this->only($ticket->supportAssignee), new TicketActivity($ticket, 'status', true, $context));
        }
    }

    /**
     * @param  string  $side  customer|support
     */
    public function assigned(Ticket $ticket, string $side, ?User $assignee, ?User $actor): void
    {
        if (! $assignee || $assignee->is($actor)) {
            return;
        }

        $this->send(
            collect([$assignee]),
            new TicketActivity($ticket, 'assigned', $side === 'support', ['name' => $actor?->name ?? '', 'side' => $side]),
        );
    }

    /**
     * The customer-side assignee went away. Open tickets only.
     */
    public function assigneeRemoved(Ticket $ticket): void
    {
        if (! $ticket->status->isOpen()) {
            return;
        }

        $this->send(
            $this->staff->organizationOwners($ticket->organization),
            new TicketActivity($ticket, 'assignee_removed'),
        );
    }

    /**
     * Support side: the assignee, or (unassigned) every staff member via the panel.
     *
     * @param  array<string, string>  $context
     */
    private function toSupport(Ticket $ticket, string $event, array $context, ?User $exclude = null): void
    {
        if ($ticket->support_assignee_id) {
            if ($ticket->support_assignee_id !== $exclude?->id) {
                $this->send($this->only($ticket->supportAssignee), new TicketActivity($ticket, $event, true, $context));
            }

            return;
        }

        // Unassigned: the whole team sees it in the panel; no email blast
        $this->send(
            $this->staff->users()->reject(fn (User $user) => $user->is($exclude)),
            new TicketActivity($ticket, $event, true, $context, [PanelChannel::class]),
        );
    }

    /**
     * Requester and customer assignee, minus the person who did the thing.
     *
     * @return Collection<int, User>
     */
    private function customerSide(Ticket $ticket, ?User $exclude): Collection
    {
        return collect([$ticket->requester, $ticket->customerAssignee])
            ->filter(fn (?User $user) => $this->staff->isActive($user))
            ->unique('id')
            ->reject(fn (User $user) => $user->is($exclude))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function only(?User $user): Collection
    {
        return $this->staff->isActive($user) ? collect([$user]) : collect();
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, TicketActivity $notification): void
    {
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
