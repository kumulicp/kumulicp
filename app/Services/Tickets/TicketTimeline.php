<?php

namespace App\Services\Tickets;

use App\Ticket;
use App\TicketEvent;
use App\User;

/**
 * The activity timeline shown beside the conversation. Customers get a short,
 * public list; staff get everything that is not already a message.
 */
class TicketTimeline
{
    /** Events a customer may see. Internal events are filtered out as well. */
    private const CUSTOMER_TYPES = [
        'created', 'status_changed', 'assigned', 'assignee_removed', 'subject_changed', 'impact_changed',
    ];

    /** Messages are shown in the conversation, so these are not repeated here. */
    private const MESSAGE_TYPES = ['reply', 'note', 'message_edited'];

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Ticket $ticket, bool $staff): array
    {
        $events = $ticket->events()
            ->with('actor')
            ->when(! $staff, fn ($query) => $query->where('internal', false)->whereIn('type', self::CUSTOMER_TYPES))
            ->when($staff, fn ($query) => $query->whereNotIn('type', self::MESSAGE_TYPES))
            ->get();

        $names = $this->userNames($events);

        return $events->map(function (TicketEvent $event) use ($names) {
            $context = $event->context ?? [];

            if (isset($context['from']) || isset($context['to'])) {
                if (in_array($event->type, ['assigned', 'assignee_removed'], true)) {
                    $context['from_name'] = $names[$context['from'] ?? 0] ?? null;
                    $context['to_name'] = $names[$context['to'] ?? 0] ?? null;
                }
            }

            return [
                'id' => $event->id,
                'type' => $event->type,
                'actor' => $event->actor?->name,
                'context' => $context,
                'created_at' => $event->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  iterable<TicketEvent>  $events
     * @return array<int, string>
     */
    private function userNames(iterable $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            if (in_array($event->type, ['assigned', 'assignee_removed'], true)) {
                foreach (['from', 'to'] as $key) {
                    if (! empty($event->context[$key])) {
                        $ids[] = $event->context[$key];
                    }
                }
            }
        }

        return User::withTrashed()->whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }
}
