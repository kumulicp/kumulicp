<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Ticket;
use App\TicketTimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Numbers for the support desk over a period. Figures are worked out in PHP
 * from the ticket columns so they come out the same on every database.
 * Response and resolution times are wall-clock minutes between the ticket
 * being opened and the event; SLA compliance uses the working-time targets.
 */
class TicketReports
{
    /** The longest period a report covers. */
    public const MAX_DAYS = 366;

    public function __construct(private TicketSla $sla) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $created = Ticket::with(['category:id,name', 'supportAssignee:id,name', 'organization:id,name'])
            ->whereBetween('created_at', [$from, $to])->get();
        $resolved = Ticket::whereBetween('resolved_at', [$from, $to])->get(['id', 'created_at', 'resolved_at']);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'created' => $created->count(),
                'resolved' => $resolved->count(),
                'open_now' => Ticket::whereIn('status', TicketStatus::openValues())->count(),
                'unassigned_now' => Ticket::whereIn('status', TicketStatus::openValues())->whereNull('support_assignee_id')->count(),
            ],
            'first_response' => $this->durations($created, 'created_at', 'first_response_at'),
            'resolution' => $this->durations($resolved, 'created_at', 'resolved_at'),
            'sla' => [
                'first_response' => $this->compliance($created, 'sla_first_response_due_at', 'first_response_at'),
                'resolution' => $this->compliance($created, 'sla_resolution_due_at', 'resolved_at'),
            ],
            'csat' => $this->csat($created),
            'per_day' => $this->perDay($created, $resolved, $from, $to),
            'by_priority' => $this->group($created, fn (Ticket $ticket) => $ticket->priority->value),
            'by_category' => $this->group($created, fn (Ticket $ticket) => $ticket->category?->name ?? '-'),
            'by_assignee' => $this->group($created, fn (Ticket $ticket) => $ticket->supportAssignee?->name ?? '-'),
            'by_organization' => $this->group($created, fn (Ticket $ticket) => $ticket->organization?->name ?? '-', 10),
            'time' => $this->time($from, $to),
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array{count: int, average: int|null, median: int|null}
     */
    private function durations(Collection $tickets, string $start, string $end): array
    {
        $minutes = $tickets
            ->filter(fn (Ticket $ticket) => $ticket->{$start} && $ticket->{$end})
            ->map(fn (Ticket $ticket) => max(0, (int) $ticket->{$start}->diffInMinutes($ticket->{$end}, true)))
            ->sort()->values();

        if ($minutes->isEmpty()) {
            return ['count' => 0, 'average' => null, 'median' => null];
        }

        $middle = intdiv($minutes->count(), 2);
        $median = $minutes->count() % 2 ? $minutes[$middle] : intdiv($minutes[$middle - 1] + $minutes[$middle], 2);

        return ['count' => $minutes->count(), 'average' => (int) round($minutes->avg()), 'median' => $median];
    }

    /**
     * Of the tickets whose clock has run out or stopped, how many were in time.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array{measured: int, met: int, percent: float|null}
     */
    private function compliance(Collection $tickets, string $due, string $done): array
    {
        $measured = 0;
        $met = 0;

        foreach ($tickets as $ticket) {
            if ($ticket->{$due} === null) {
                continue;
            }

            if ($ticket->{$done} !== null) {
                $measured++;
                $met += $ticket->{$done}->lte($ticket->{$due}) ? 1 : 0;
            } elseif (now()->gt($ticket->{$due})) {
                // Still not done and already late
                $measured++;
            }
        }

        return ['measured' => $measured, 'met' => $met, 'percent' => $measured ? round($met / $measured * 100, 1) : null];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array{count: int, average: float|null, distribution: array<int, int>}
     */
    private function csat(Collection $tickets): array
    {
        $rated = $tickets->filter(fn (Ticket $ticket) => $ticket->csat_score !== null);
        $distribution = array_fill_keys([1, 2, 3, 4, 5], 0);

        foreach ($rated as $ticket) {
            $distribution[$ticket->csat_score]++;
        }

        return [
            'count' => $rated->count(),
            'average' => $rated->isEmpty() ? null : round($rated->avg('csat_score'), 2),
            'distribution' => $distribution,
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $created
     * @param  Collection<int, Ticket>  $resolved
     * @return list<array{date: string, created: int, resolved: int}>
     */
    private function perDay(Collection $created, Collection $resolved, CarbonInterface $from, CarbonInterface $to): array
    {
        $createdByDay = $created->countBy(fn (Ticket $ticket) => $ticket->created_at->toDateString());
        $resolvedByDay = $resolved->countBy(fn (Ticket $ticket) => $ticket->resolved_at->toDateString());
        $days = [];

        for ($day = $from->copy(); $day->lte($to) && count($days) <= self::MAX_DAYS; $day->addDay()) {
            $date = $day->toDateString();
            $days[] = ['date' => $date, 'created' => (int) ($createdByDay[$date] ?? 0), 'resolved' => (int) ($resolvedByDay[$date] ?? 0)];
        }

        return $days;
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return list<array{name: string, tickets: int, first_response: int|null, csat: float|null}>
     */
    private function group(Collection $tickets, callable $by, ?int $limit = null): array
    {
        $groups = $tickets->groupBy($by)->map(function (Collection $group, string $name) {
            $times = $this->durations($group, 'created_at', 'first_response_at');
            $rated = $group->whereNotNull('csat_score');

            return [
                'name' => $name,
                'tickets' => $group->count(),
                'first_response' => $times['average'],
                'csat' => $rated->isEmpty() ? null : round($rated->avg('csat_score'), 2),
            ];
        })->sortByDesc('tickets')->values();

        return ($limit ? $groups->take($limit) : $groups)->all();
    }

    /**
     * Time staff logged in the period, by organization and category.
     *
     * @return array{total: int, billable: int, by_organization: list<array<string, mixed>>, by_category: list<array<string, mixed>>}
     */
    private function time(CarbonInterface $from, CarbonInterface $to): array
    {
        $entries = TicketTimeEntry::whereBetween('worked_at', [$from->toDateString(), $to->toDateString()])->get();
        $tickets = Ticket::with(['organization:id,name', 'category:id,name'])->whereIn('id', $entries->pluck('ticket_id')->unique())->get()->keyBy('id');

        $sum = function (callable $label) use ($entries, $tickets) {
            return $entries->groupBy(fn (TicketTimeEntry $entry) => $label($tickets->get($entry->ticket_id)))
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'minutes' => $group->sum('minutes'),
                    'billable' => $group->where('billable', true)->sum('minutes'),
                ])->sortByDesc('minutes')->values()->take(10)->all();
        };

        return [
            'total' => $entries->sum('minutes'),
            'billable' => $entries->where('billable', true)->sum('minutes'),
            'by_organization' => $sum(fn (?Ticket $ticket) => $ticket?->organization?->name ?? '-'),
            'by_category' => $sum(fn (?Ticket $ticket) => $ticket?->category?->name ?? '-'),
        ];
    }
}
