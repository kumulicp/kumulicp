<?php

namespace App\Services\Tickets\Integrations;

use App\Enums\TicketStatus;
use App\Services\Tickets\TicketService;
use App\Ticket;
use App\TicketExternalLink;
use App\TicketInboundEvent;
use App\TicketIntegration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * What a ticket does when the issue it is linked to changes in the tracker:
 *
 *  - a comment becomes an internal note (staff only; never shown to customers)
 *  - closing or reopening the issue is noted, and, if the integration is set
 *    to, resolves or reopens the ticket
 *
 * Comments we posted ourselves are recognised by their hidden tag and ignored,
 * and an event we have already handled does nothing the second time.
 */
class InboundHandler
{
    public const IGNORED = 'ignored';

    public const DUPLICATE = 'duplicate';

    public const NOTED = 'noted';

    public const CHANGED = 'changed';

    public function __construct(private TicketService $tickets) {}

    public function handle(TicketIntegration $integration, InboundEvent $event): string
    {
        if (! $this->firstTime($integration, $event)) {
            return self::DUPLICATE;
        }

        $link = TicketExternalLink::where('integration_id', $integration->id)->where('external_id', $event->issueId)->first();
        $ticket = $link ? Ticket::find($link->ticket_id) : null;

        if (! $link || ! $ticket) {
            return self::IGNORED;
        }

        return $event->kind === InboundEvent::COMMENT
            ? $this->comment($integration, $link, $ticket, $event)
            : $this->state($integration, $link, $ticket, $event);
    }

    private function comment(TicketIntegration $integration, TicketExternalLink $link, Ticket $ticket, InboundEvent $event): string
    {
        // Our own comment coming back, or comments switched off
        if (! $integration->option('inbound_comments', true) || str_contains($event->body, '<!-- kcp:')) {
            return self::IGNORED;
        }

        $text = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($event->body)) ?? ''), 1500);

        if ($text === '') {
            return self::IGNORED;
        }

        $this->tickets->addSystemNote($ticket, __('tickets.inbound.comment', [
            'author' => $event->author !== '' ? $event->author : __('tickets.inbound.someone'),
            'issue' => $link->external_key ?? '#'.$link->external_id,
            'integration' => $integration->name,
            'text' => $text,
        ]));

        return self::NOTED;
    }

    private function state(TicketIntegration $integration, TicketExternalLink $link, Ticket $ticket, InboundEvent $event): string
    {
        if (! in_array($event->state, ['open', 'closed'], true) || $link->external_state === $event->state) {
            return self::IGNORED;
        }

        // Record the new state first: if the ticket changing makes us tell the
        // tracker the same thing, there is nothing left to say
        $link->external_state = $event->state;
        $link->last_synced_at = now();
        $link->save();

        $this->tickets->addSystemNote($ticket, __('tickets.inbound.'.($event->state === 'closed' ? 'closed' : 'reopened'), [
            'issue' => $link->external_key ?? '#'.$link->external_id,
            'integration' => $integration->name,
        ]));

        if ($integration->option('inbound_resolve', false) && ! $ticket->isMerged()) {
            if ($event->state === 'closed' && $ticket->status->isOpen()) {
                $this->tickets->changeStatus($ticket, null, TicketStatus::RESOLVED, true);

                return self::CHANGED;
            }

            if ($event->state === 'open' && ! $ticket->status->isOpen()) {
                $this->tickets->changeStatus($ticket, null, TicketStatus::OPEN, true);

                return self::CHANGED;
            }
        }

        return self::NOTED;
    }

    /**
     * Remember the event; false if we already had it.
     */
    private function firstTime(TicketIntegration $integration, InboundEvent $event): bool
    {
        $record = new TicketInboundEvent;
        $record->integration_id = $integration->id;
        $record->event_key = mb_substr($event->kind.':'.$event->key, 0, 250);

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
