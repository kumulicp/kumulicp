<?php

namespace App\Services\Tickets;

use App\Ticket;
use App\TicketMessage;
use App\TicketTimeEntry;
use App\User;
use Carbon\CarbonInterface;
use Generator;

/**
 * Ticket data leaving the system as files: a spreadsheet of tickets for
 * reporting, and everything one person has put into the desk, for a data
 * subject access request.
 */
class TicketExporter
{
    public function __construct(private TicketSla $sla) {}

    /**
     * @return list<string>
     */
    public function csvHeader(): array
    {
        return [
            'Reference', 'Subject', 'Organization', 'Requester', 'Type', 'Status', 'Priority', 'Impact', 'Category', 'Support assignee',
            'Created', 'First response', 'Resolved', 'Closed', 'Minutes to first response', 'Minutes to resolution',
            'First response SLA', 'Resolution SLA', 'Satisfaction', 'Minutes logged', 'Billable minutes',
        ];
    }

    /**
     * One row per ticket opened in the period.
     *
     * @return Generator<int, list<string|int|null>>
     */
    public function csvRows(CarbonInterface $from, CarbonInterface $to): Generator
    {
        $lastId = 0;

        // Page by id so a big period never has to fit in memory at once
        while (true) {
            $chunk = Ticket::with(['organization:id,name', 'requester:id,name', 'category:id,name', 'supportAssignee:id,name'])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(200)
                ->get();

            if ($chunk->isEmpty()) {
                return;
            }

            $lastId = $chunk->last()->id;
            $time = TicketTimeEntry::whereIn('ticket_id', $chunk->pluck('id'))->get()->groupBy('ticket_id');

            foreach ($chunk as $ticket) {
                $state = $this->sla->state($ticket);
                $entries = $time->get($ticket->id, collect());

                yield array_map([$this, 'cell'], [
                    $ticket->reference, $ticket->subject, $ticket->organization?->name, $ticket->requester?->name,
                    $ticket->type->value, $ticket->status->value, $ticket->priority->value, $ticket->impact->value,
                    $ticket->category?->name, $ticket->supportAssignee?->name,
                    $ticket->created_at?->toIso8601String(), $ticket->first_response_at?->toIso8601String(),
                    $ticket->resolved_at?->toIso8601String(), $ticket->closed_at?->toIso8601String(),
                    $ticket->first_response_at ? (int) $ticket->created_at->diffInMinutes($ticket->first_response_at, true) : null,
                    $ticket->resolved_at ? (int) $ticket->created_at->diffInMinutes($ticket->resolved_at, true) : null,
                    $state['first_response'], $state['resolution'], $ticket->csat_score,
                    $entries->sum('minutes'), $entries->where('billable', true)->sum('minutes'),
                ]);
            }
        }
    }

    /**
     * A spreadsheet opens text starting with = + - @ as a formula, so text a
     * customer typed could run something on the machine of whoever opens the
     * export. A leading apostrophe makes it plain text.
     */
    public function cell(mixed $value): string|int|null
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /**
     * Everything the person has put into the support desk.
     *
     * @return array<string, mixed>
     */
    public function userData(User $user): array
    {
        $requested = Ticket::withTrashed()->where('requester_id', $user->id)->orderBy('id')->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'person' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'tickets_opened' => $requested->map(fn (Ticket $ticket) => [
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'satisfaction' => $ticket->csat_score,
                'satisfaction_comment' => $ticket->csat_comment,
            ])->all(),
            'messages_written' => TicketMessage::withTrashed()->with('ticket:id,number')->where('author_id', $user->id)->orderBy('id')->get()
                ->map(fn (TicketMessage $message) => [
                    'ticket' => $message->ticket ? config('tickets.prefix').'-'.$message->ticket->number : null,
                    'visibility' => $message->visibility,
                    'written_at' => $message->created_at?->toIso8601String(),
                    'text' => $message->redacted_at ? null : $message->body_text,
                ])->all(),
            'time_logged' => TicketTimeEntry::where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (TicketTimeEntry $entry) => ['ticket_id' => $entry->ticket_id, 'minutes' => $entry->minutes, 'note' => $entry->note, 'date' => $entry->worked_at->toDateString()])->all(),
        ];
    }
}
