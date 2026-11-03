<?php

namespace App\Services\Tickets;

use App\Channels\PanelChannel;
use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use App\Support\Facades\Settings;
use App\Ticket;
use App\TicketMessage;
use App\TicketSubscription;
use App\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Decides who is told about what. The rule that matters: internal notes only
 * ever reach staff. Whether a particular person actually gets the message by
 * email or in the panel is decided later, per person, in TicketActivity.
 */
class TicketNotifier
{
    public function __construct(
        private TicketStaff $staff,
        private TicketSubscriptions $subscriptions,
    ) {}

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
            'name' => $author?->name ?? ($message->author_kind === TicketMessage::KIND_SYSTEM ? __('tickets.system_label') : ''),
            'excerpt' => Str::limit($message->body_text, 300),
        ];

        if ($message->isInternal()) {
            $this->send(
                $this->supportSide($ticket)->reject(fn (User $user) => $user->is($author)),
                new TicketActivity($ticket, 'note', true, $context, null, $message->id),
            );

            return;
        }

        if ($message->isStaff()) {
            $this->send($this->customerSide($ticket, $author), new TicketActivity($ticket, 'reply', false, $context, null, $message->id));

            // Colleagues on the support side who are watching
            $this->send(
                $this->subscriptions->watchers($ticket, TicketSubscription::SUPPORT)->reject(fn (User $user) => $user->is($author)),
                new TicketActivity($ticket, 'reply', true, $context, null, $message->id),
            );

            return;
        }

        // A customer replied: tell support, and any colleagues on the customer side
        $this->toSupport($ticket, 'reply', $context, $author, $message->id);
        $this->send($this->customerSide($ticket, $author), new TicketActivity($ticket, 'reply', false, $context, null, $message->id));
    }

    public function statusChanged(Ticket $ticket, TicketStatus $from, ?User $actor): void
    {
        $context = ['status' => $ticket->status->label(), 'name' => $actor?->name ?? ''];

        $this->send($this->customerSide($ticket, $actor), new TicketActivity($ticket, 'status', false, $context));
        $this->send(
            $this->supportSide($ticket)->reject(fn (User $user) => $user->is($actor)),
            new TicketActivity($ticket, 'status', true, $context),
        );
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
     * The ticket has been waiting on the customer for a while.
     */
    public function reminder(Ticket $ticket): void
    {
        $this->send($this->customerSide($ticket, null), new TicketActivity($ticket, 'reminder'));
    }

    /**
     * The ticket was folded into another one.
     */
    public function merged(Ticket $source, Ticket $target): void
    {
        $this->send($this->customerSide($source, null), new TicketActivity($source, 'merged', false, ['target' => $target->reference]));
    }

    /**
     * A service target is about to run out (kind "warning") or has (kind "breached").
     */
    public function slaAlert(Ticket $ticket, string $kind, string $clock): void
    {
        $this->toSupport($ticket, 'sla_'.$kind, ['clock' => __('tickets.sla.clocks.'.$clock)]);
    }

    /**
     * A customer gave a poor rating.
     */
    public function lowRating(Ticket $ticket, User $by, int $score): void
    {
        $this->toSupport($ticket, 'rating', ['name' => $by->name, 'score' => (string) $score]);
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
     * Support side: the assignee and watchers, or (unassigned) every staff
     * member via the panel.
     *
     * @param  array<string, string>  $context
     */
    private function toSupport(Ticket $ticket, string $event, array $context, ?User $exclude = null, ?int $messageId = null): void
    {
        if ($ticket->support_assignee_id) {
            $this->send(
                $this->supportSide($ticket)->reject(fn (User $user) => $user->is($exclude)),
                new TicketActivity($ticket, $event, true, $context, null, $messageId),
            );

            return;
        }

        // Unassigned: the whole team sees it in the panel; no email blast
        $this->send(
            $this->staff->users()->reject(fn (User $user) => $user->is($exclude)),
            new TicketActivity($ticket, $event, true, $context, [PanelChannel::class], $messageId),
        );

        // Staff watching an unassigned ticket still get their email
        $this->send(
            $this->subscriptions->watchers($ticket, TicketSubscription::SUPPORT)->reject(fn (User $user) => $user->is($exclude)),
            new TicketActivity($ticket, $event, true, $context, ['mail'], $messageId),
        );
    }

    /**
     * Requester, customer assignee and CC'd colleagues, minus the person who did the thing.
     *
     * @return Collection<int, User>
     */
    private function customerSide(Ticket $ticket, ?User $exclude): Collection
    {
        return collect([$ticket->requester, $ticket->customerAssignee])
            ->merge($this->subscriptions->watchers($ticket, TicketSubscription::CUSTOMER))
            ->filter(fn (?User $user) => $this->staff->isActive($user))
            ->unique('id')
            ->reject(fn (User $user) => $user->is($exclude))
            ->values();
    }

    /**
     * The support assignee and staff watchers.
     *
     * @return Collection<int, User>
     */
    private function supportSide(Ticket $ticket): Collection
    {
        return collect([$ticket->supportAssignee])
            ->merge($this->subscriptions->watchers($ticket, TicketSubscription::SUPPORT))
            ->filter(fn (?User $user) => $this->staff->isActive($user))
            ->unique('id')
            ->values();
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
