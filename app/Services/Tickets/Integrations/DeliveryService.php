<?php

namespace App\Services\Tickets\Integrations;

use App\Contracts\Tickets\ReceivesEvents;
use App\Contracts\Tickets\TracksIssues;
use App\Services\Tickets\TicketVersions;
use App\Ticket;
use App\TicketDelivery;
use App\TicketEvent;
use App\TicketExternalLink;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Carries out one delivery with the right driver. Failures are thrown as
 * IntegrationException; DeliveryRunner decides what to do about them.
 */
class DeliveryService
{
    public function __construct(
        private TicketIntegrationRegistry $registry,
        private TicketVersions $versions,
    ) {}

    /**
     * @throws IntegrationException
     */
    public function perform(TicketDelivery $delivery): DeliveryResult
    {
        $integration = $delivery->integration;
        $driver = $this->registry->driver($integration->driver)
            ?? throw IntegrationException::permanent("The \"{$integration->driver}\" integration type is not installed.");

        $config = new IntegrationConfig($integration->config ?? [], (bool) $integration->option('allow_internal', false));

        if ($driver instanceof ReceivesEvents) {
            return $driver->send($config, $delivery->event, $delivery->payload, (string) $delivery->id);
        }

        if ($driver instanceof TracksIssues) {
            return $this->track($driver, $config, $delivery);
        }

        throw IntegrationException::permanent('This integration type cannot receive tickets.');
    }

    private function track(TracksIssues $driver, IntegrationConfig $config, TicketDelivery $delivery): DeliveryResult
    {
        $link = TicketExternalLink::where('ticket_id', $delivery->ticket_id)
            ->where('integration_id', $delivery->integration_id)
            ->first();

        $payload = $delivery->payload;

        switch ($delivery->event) {
            case 'ticket.created':
            case 'issue.create':
                if ($link) {
                    return DeliveryResult::nothingToDo('Already linked to issue '.$link->external_key.'.');
                }

                $result = $driver->createIssue($config, $payload);

                if (! $result->issue) {
                    throw IntegrationException::permanent('The tracker did not say which issue was created.');
                }

                $this->link($delivery, $result->issue);

                return $result;

            case 'message.created':
                return $driver->addComment($config, $this->issue($link), $payload);

            case 'status.changed':
                // What the ticket is now, not what it was when this was queued: if it was
                // resolved and reopened since, the issue should not be closed and reopened too
                $current = Ticket::find($delivery->ticket_id);
                $open = $current ? $current->status->isOpen() : ($payload['ticket']['open'] ?? true);
                $desired = $open ? 'open' : 'closed';
                $issue = $this->issue($link);

                if (($link->external_state ?? 'open') === $desired) {
                    return DeliveryResult::nothingToDo("The issue is already {$desired}.");
                }

                $result = $driver->updateState($config, $issue, $desired);
                $link->external_state = $desired;
                $link->last_synced_at = now();
                $link->save();

                return $result;

            case 'time.logged':
                return $driver->logTime($config, $this->issue($link), $payload);
        }

        throw IntegrationException::permanent("This integration does not handle \"{$delivery->event}\".");
    }

    private function issue(?TicketExternalLink $link): ExternalIssue
    {
        if (! $link) {
            throw IntegrationException::permanent('There is no issue linked to this ticket in that tracker.');
        }

        return new ExternalIssue($link->external_id, $link->external_key, $link->external_url, $link->external_state ?? 'open');
    }

    private function link(TicketDelivery $delivery, ExternalIssue $issue): void
    {
        $link = new TicketExternalLink;
        $link->ticket_id = $delivery->ticket_id;
        $link->integration_id = $delivery->integration_id;
        $link->external_id = $issue->id;
        $link->external_key = $issue->key;
        $link->external_url = $issue->url;
        $link->external_state = $issue->state;
        $link->last_synced_at = now();

        try {
            $link->save();
        } catch (UniqueConstraintViolationException) {
            // Already recorded by an earlier attempt that failed after the tracker answered
            return;
        }

        $event = new TicketEvent;
        $event->ticket_id = $delivery->ticket_id;
        $event->type = 'issue_linked';
        $event->context = ['integration' => $delivery->integration->name, 'key' => $issue->key, 'url' => $issue->url];
        $event->internal = true;
        $event->save();

        $ticket = $delivery->ticket;
        if ($ticket) {
            $this->versions->bump($ticket);
        }
    }
}
