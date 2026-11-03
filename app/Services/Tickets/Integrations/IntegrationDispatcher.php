<?php

namespace App\Services\Tickets\Integrations;

use App\Contracts\Tickets\ReceivesEvents;
use App\Contracts\Tickets\TracksIssues;
use App\Jobs\Tickets\DeliverTicketIntegration;
use App\Ticket;
use App\TicketDelivery;
use App\TicketExternalLink;
use App\TicketIntegration;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns something that happened on a ticket into deliveries to every
 * integration that wants to know. The payload is built now, with each
 * integration's privacy options applied, and stored with the delivery, so what
 * is sent later is exactly what was allowed out at the time.
 */
class IntegrationDispatcher
{
    public function __construct(
        private TicketIntegrationRegistry $registry,
        private PayloadBuilder $payloads,
    ) {}

    /**
     * @param  array<string, mixed>  $subject  What the event is about (see PayloadBuilder)
     * @param  string|int  $key  Identifies this occurrence so it is never delivered twice
     */
    public function dispatch(string $event, Ticket $ticket, array $subject = [], string|int $key = ''): void
    {
        foreach (TicketIntegration::where('enabled', true)->get() as $integration) {
            if ($integration->matches($ticket) && $this->wants($integration, $event, $ticket)) {
                $this->queue($integration, $event, $ticket, $subject, "{$event}:{$integration->id}:{$ticket->id}:{$key}");
            }
        }
    }

    /**
     * Create the ticket's issue in a tracker on request. Nothing happens if it
     * already has one there, or one is already on its way.
     */
    public function createIssue(TicketIntegration $integration, Ticket $ticket): ?TicketDelivery
    {
        $driver = $this->registry->driver($integration->driver);

        if (! $driver instanceof TracksIssues || $this->linked($integration, $ticket) || $this->creating($integration, $ticket)) {
            return null;
        }

        $attempt = TicketDelivery::where('integration_id', $integration->id)->where('ticket_id', $ticket->id)->where('event', 'issue.create')->count() + 1;

        return $this->queue($integration, 'issue.create', $ticket, [], "issue.create:{$integration->id}:{$ticket->id}:{$attempt}");
    }

    private function wants(TicketIntegration $integration, string $event, Ticket $ticket): bool
    {
        $driver = $this->registry->driver($integration->driver);

        if ($driver instanceof ReceivesEvents) {
            return in_array($event, $integration->events ?? [], true);
        }

        if (! $driver instanceof TracksIssues) {
            return false;
        }

        return match ($event) {
            'ticket.created' => (bool) $integration->option('auto_create', false),
            // Follow-ups only matter once there is an issue, or one is being created right now
            'message.created' => $this->hasOrWillHaveIssue($integration, $ticket),
            'status.changed' => $integration->option('sync_state', true) && $this->hasOrWillHaveIssue($integration, $ticket),
            'time.logged' => $integration->option('sync_time', true) && $this->hasOrWillHaveIssue($integration, $ticket),
            default => false,
        };
    }

    private function hasOrWillHaveIssue(TicketIntegration $integration, Ticket $ticket): bool
    {
        return $this->linked($integration, $ticket) || $this->creating($integration, $ticket);
    }

    private function linked(TicketIntegration $integration, Ticket $ticket): bool
    {
        return TicketExternalLink::where('integration_id', $integration->id)->where('ticket_id', $ticket->id)->exists();
    }

    private function creating(TicketIntegration $integration, Ticket $ticket): bool
    {
        return TicketDelivery::where('integration_id', $integration->id)
            ->where('ticket_id', $ticket->id)
            ->where('status', TicketDelivery::PENDING)
            ->whereIn('event', ['ticket.created', 'issue.create'])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function queue(TicketIntegration $integration, string $event, Ticket $ticket, array $subject, string $idempotencyKey): ?TicketDelivery
    {
        $delivery = new TicketDelivery;
        $delivery->integration_id = $integration->id;
        $delivery->ticket_id = $ticket->id;
        $delivery->event = $event;
        $delivery->idempotency_key = $idempotencyKey;
        $delivery->status = TicketDelivery::PENDING;
        $delivery->payload = $this->payloads->build($integration, $event, $ticket, $subject);

        try {
            $delivery->save();
        } catch (UniqueConstraintViolationException) {
            // The same occurrence was already queued
            return null;
        }

        DeliverTicketIntegration::dispatch($delivery->id);

        return $delivery;
    }
}
