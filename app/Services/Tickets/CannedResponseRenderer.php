<?php

namespace App\Services\Tickets;

use App\Ticket;
use App\TicketCannedResponse;
use App\User;

/**
 * Fills the {{variables}} of a canned response in for a particular ticket.
 *
 * Values come from people (names, subjects) so they are escaped before they go
 * into the HTML, and the result is run through the ticket sanitiser like any
 * other message. Unknown variables are left as written so a typo is visible.
 *
 *   {{requester.name}}  {{customer.name}}  {{org.name}}  {{ticket.reference}}
 *   {{ticket.subject}}  {{ticket.url}}  {{agent.name}}  {{assignee.name}}
 */
class CannedResponseRenderer
{
    public function __construct(private TicketHtml $html) {}

    public function render(TicketCannedResponse $response, Ticket $ticket, User $agent): string
    {
        $ticket->loadMissing(['requester', 'customerAssignee', 'supportAssignee', 'organization']);

        $values = [
            'requester.name' => $ticket->requester?->name,
            'customer.name' => $ticket->customerAssignee?->name ?? $ticket->requester?->name,
            'org.name' => $ticket->organization?->name,
            'ticket.reference' => $ticket->reference,
            'ticket.subject' => $ticket->subject,
            'ticket.url' => url('/support/tickets/'.$ticket->number),
            'agent.name' => $agent->name,
            'assignee.name' => $ticket->supportAssignee?->name ?? $agent->name,
        ];

        $filled = preg_replace_callback('/\{\{\s*([a-z]+\.[a-z]+)\s*\}\}/i', function (array $match) use ($values) {
            $key = strtolower($match[1]);

            return array_key_exists($key, $values) && $values[$key] !== null
                ? e($values[$key])
                : $match[0];
        }, $response->body_html) ?? $response->body_html;

        return $this->html->sanitize($filled);
    }
}
