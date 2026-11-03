<?php

namespace App\Services\Tickets;

use App\Ticket;
use App\TicketSubscription;
use App\User;
use Illuminate\Support\Collection;

/**
 * Watchers (people CC'd on a ticket) and muting. The ticket's own roles
 * (requester, assignees) are separate and not stored here.
 */
class TicketSubscriptions
{
    public function __construct(private TicketStaff $staff) {}

    /**
     * Active users watching the ticket on one side.
     *
     * @return Collection<int, User>
     */
    public function watchers(Ticket $ticket, string $side): Collection
    {
        return TicketSubscription::with('user')
            ->where('ticket_id', $ticket->id)
            ->where('side', $side)
            ->where('watching', true)
            ->get()
            ->map(fn (TicketSubscription $subscription) => $subscription->user)
            ->filter(fn (?User $user) => $this->staff->isActive($user))
            ->values();
    }

    /**
     * @return list<int>
     */
    public function watcherIds(Ticket $ticket, ?string $side = null): array
    {
        return TicketSubscription::where('ticket_id', $ticket->id)
            ->where('watching', true)
            ->when($side, fn ($query) => $query->where('side', $side))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Add one person as a watcher without touching anyone else. Someone already
     * on the list keeps the side they are on.
     */
    public function addWatcher(Ticket $ticket, int $userId, string $side): void
    {
        $subscription = TicketSubscription::firstOrNew(['ticket_id' => $ticket->id, 'user_id' => $userId]);

        if (! $subscription->exists) {
            $subscription->side = $side;
        }

        $subscription->watching = true;
        $subscription->save();
    }

    public function isMuted(Ticket $ticket, User $user): bool
    {
        return TicketSubscription::where('ticket_id', $ticket->id)->where('user_id', $user->id)->where('muted', true)->exists();
    }

    public function setMuted(Ticket $ticket, User $user, bool $muted, string $side): void
    {
        $subscription = TicketSubscription::firstOrNew(['ticket_id' => $ticket->id, 'user_id' => $user->id]);

        if (! $subscription->exists) {
            $subscription->side = $side;
        }

        $subscription->muted = $muted;
        $this->saveOrDrop($subscription);
    }

    /**
     * Make exactly $userIds the watchers on the given sides.
     *
     * @param  array<int, string>  $desired  user id => side
     * @param  list<string>  $managedSides  Only rows on these sides are added or removed
     * @return array{added: list<int>, removed: list<int>}
     */
    public function sync(Ticket $ticket, array $desired, array $managedSides): array
    {
        $existing = TicketSubscription::where('ticket_id', $ticket->id)->get()->keyBy('user_id');
        $added = [];
        $removed = [];

        foreach ($desired as $userId => $side) {
            $subscription = $existing->get($userId) ?? new TicketSubscription(['ticket_id' => $ticket->id, 'user_id' => $userId]);

            if (! $subscription->watching) {
                $added[] = $userId;
            }

            $subscription->side = $side;
            $subscription->watching = true;
            $subscription->save();
        }

        foreach ($existing as $userId => $subscription) {
            if ($subscription->watching && in_array($subscription->side, $managedSides, true) && ! array_key_exists($userId, $desired)) {
                $subscription->watching = false;
                $this->saveOrDrop($subscription);
                $removed[] = $userId;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * A row that neither watches nor mutes carries no information.
     */
    private function saveOrDrop(TicketSubscription $subscription): void
    {
        if (! $subscription->watching && ! $subscription->muted) {
            if ($subscription->exists) {
                $subscription->delete();
            }

            return;
        }

        $subscription->save();
    }
}
