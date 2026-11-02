<?php

namespace App\Services\Tickets\Integrations;

use App\Services\Tickets\DurationParser;
use App\Ticket;
use App\TicketIntegration;
use App\TicketMessage;
use App\TicketTimeEntry;
use LogicException;

/**
 * Builds what leaves the system for an event, once, when it happens. The
 * integration's privacy options are applied here, so everything downstream
 * (the delivery log, retries, the driver) only ever sees what is allowed out:
 *
 *  - subject, public messages, organization name and the ticket link: always
 *  - requester and staff names/emails: only if "include requester" is on
 *  - internal notes: never
 *  - attachments: names and sizes only, never the files
 */
class PayloadBuilder
{
    public function __construct(private HtmlToMarkdown $markdown) {}

    /**
     * @param  array{message?: TicketMessage, time?: TicketTimeEntry, from?: string, assignee?: string, side?: string}  $subject
     * @return array<string, mixed>
     */
    public function build(TicketIntegration $integration, string $event, Ticket $ticket, array $subject = []): array
    {
        $names = (bool) $integration->option('include_requester', false);
        $ticket->load(['organization', 'requester', 'category', 'tags']);

        $payload = [
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'ticket' => $this->ticket($ticket, $names),
        ];

        if (in_array($event, ['ticket.created', 'issue.create'], true)) {
            // The opening message, so a new issue says what the ticket is about
            $first = $ticket->publicMessages()->with(['author', 'attachments'])->first();
            if ($first) {
                $payload['message'] = $this->message($first, $names);
            }
        }

        if (isset($subject['message'])) {
            $payload['message'] = $this->message($subject['message'], $names);
        }

        if (isset($subject['time'])) {
            $payload['time_entry'] = $this->time($subject['time'], $names);
        }

        if (isset($subject['from'])) {
            $payload['changes'] = ['status' => ['from' => $subject['from'], 'to' => $ticket->status->value]];
        }

        if (isset($subject['side'])) {
            $payload['changes'] = ['assignee' => ['side' => $subject['side'], 'name' => $names ? ($subject['assignee'] ?? null) : null]];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(Ticket $ticket, bool $names): array
    {
        $data = [
            'number' => $ticket->number,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'url' => url('/admin/support/tickets/'.$ticket->number),
            'type' => $ticket->type->value,
            'status' => $ticket->status->value,
            'open' => $ticket->status->isOpen(),
            'priority' => $ticket->priority->value,
            'impact' => $ticket->impact->value,
            'category' => $ticket->category?->name,
            'tags' => $ticket->tags->pluck('name')->values()->all(),
            'organization' => ['name' => $ticket->organization?->name],
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];

        if ($names && $ticket->requester) {
            $data['requester'] = ['name' => $ticket->requester->name, 'email' => $ticket->requester->email];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function message(TicketMessage $message, bool $names): array
    {
        if ($message->isInternal()) {
            throw new LogicException('Internal notes are never sent to integrations.');
        }

        $message->loadMissing(['author', 'attachments']);

        return [
            'id' => $message->id,
            'author' => [
                'kind' => $message->isStaff() ? 'support' : 'customer',
                'name' => $names ? $message->author?->name : null,
            ],
            'body_markdown' => $this->markdown->convert($message->body_html),
            'body_text' => $message->body_text,
            'body_html' => $message->body_html,
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'mime' => $attachment->mime,
            ])->values()->all(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function time(TicketTimeEntry $entry, bool $names): array
    {
        $entry->loadMissing('user');

        return [
            'id' => $entry->id,
            'minutes' => $entry->minutes,
            'duration' => DurationParser::format($entry->minutes),
            'note' => $entry->note,
            'billable' => $entry->billable,
            'worked_at' => $entry->worked_at->toDateString(),
            'by' => $names ? $entry->user?->name : null,
        ];
    }
}
