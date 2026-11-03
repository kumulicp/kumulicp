<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Ticket;
use App\TicketCategory;
use App\TicketSlaPolicy;
use Carbon\CarbonInterface;

/**
 * Service level targets for tickets.
 *
 * Every ticket gets the policy of its category, or the default policy, and from
 * it two clocks that start when the ticket is opened:
 *   first response  runs until staff first answer the customer
 *   resolution      runs until the ticket is resolved, and stops while the
 *                   ticket is waiting on the customer or on hold
 * Due times count working minutes only when the policy has business hours.
 */
class TicketSla
{
    /** How little of a target may remain before staff are warned. */
    public const WARN_WHEN_REMAINING = 0.2;

    /** @var array<int, TicketSlaPolicy|null> */
    private array $policies = [];

    public function policyFor(Ticket $ticket): ?TicketSlaPolicy
    {
        $categoryPolicy = $ticket->category_id
            ? TicketCategory::whereKey($ticket->category_id)->value('sla_policy_id')
            : null;

        if ($categoryPolicy && ($policy = TicketSlaPolicy::where('active', true)->find($categoryPolicy))) {
            return $policy;
        }

        return TicketSlaPolicy::where('active', true)->where('is_default', true)->first();
    }

    /**
     * Work out the policy and due times from the ticket as it stands and set
     * them on the model. Call when a ticket is created and when its priority or
     * category changes; the caller saves.
     */
    public function apply(Ticket $ticket): void
    {
        $policy = $this->policyFor($ticket);

        if (! $policy) {
            $ticket->sla_policy_id = null;
            $ticket->sla_first_response_due_at = null;
            $ticket->sla_resolution_due_at = null;

            return;
        }

        $calendar = $this->calendar($policy);
        $start = $ticket->created_at ?? now();
        $priority = $ticket->priority->value;

        $first = $policy->target($priority, 'first_response');
        $resolution = $policy->target($priority, 'resolution');

        $ticket->sla_policy_id = $policy->id;
        $ticket->sla_first_response_due_at = $first ? $calendar->addMinutes($start, $first) : null;
        $ticket->sla_resolution_due_at = $resolution ? $calendar->addMinutes($start, $resolution + $ticket->sla_paused_minutes) : null;
    }

    /**
     * Stop the resolution clock while the ticket waits on the customer or is on
     * hold, and start it again afterwards (pushing the due time back by the
     * working time that passed). Call after every status change; the caller saves.
     */
    public function statusChanged(Ticket $ticket): void
    {
        if (! $ticket->sla_policy_id) {
            return;
        }

        $pausing = in_array($ticket->status, [TicketStatus::PENDING_CUSTOMER, TicketStatus::ON_HOLD], true);

        if ($pausing && $ticket->sla_paused_at === null) {
            $ticket->sla_paused_at = now();

            return;
        }

        if (! $pausing && $ticket->sla_paused_at !== null) {
            $policy = $this->policy($ticket->sla_policy_id);

            if ($policy) {
                $calendar = $this->calendar($policy);
                $ticket->sla_paused_minutes += $calendar->minutesBetween($ticket->sla_paused_at, now());
                $ticket->sla_paused_at = null;

                $resolution = $policy->target($ticket->priority->value, 'resolution');
                $ticket->sla_resolution_due_at = $resolution ? $calendar->addMinutes($ticket->created_at, $resolution + $ticket->sla_paused_minutes) : null;
            } else {
                $ticket->sla_paused_at = null;
            }
        }
    }

    /**
     * Where each clock stands: none, ok, warning, breached, paused, met, missed.
     *
     * @return array{first_response: string, resolution: string}
     */
    public function state(Ticket $ticket, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'first_response' => $this->clockState($ticket, 'first_response', $ticket->sla_first_response_due_at, $ticket->first_response_at, false, $now),
            'resolution' => $this->clockState($ticket, 'resolution', $ticket->sla_resolution_due_at, $ticket->status->isOpen() ? null : ($ticket->resolved_at ?? $ticket->closed_at), $ticket->sla_paused_at !== null, $now),
        ];
    }

    public function calendar(TicketSlaPolicy $policy): BusinessCalendar
    {
        return new BusinessCalendar($policy->business_hours);
    }

    private function clockState(Ticket $ticket, string $clock, ?CarbonInterface $due, ?CarbonInterface $finishedAt, bool $paused, CarbonInterface $now): string
    {
        if ($due === null) {
            return 'none';
        }

        if ($finishedAt !== null) {
            return $finishedAt->lte($due) ? 'met' : 'missed';
        }

        if ($paused) {
            return 'paused';
        }

        if ($now->gt($due)) {
            return 'breached';
        }

        $policy = $ticket->sla_policy_id ? $this->policy($ticket->sla_policy_id) : null;
        $target = $policy?->target($ticket->priority->value, $clock);

        if ($policy && $target) {
            $remaining = $this->calendar($policy)->minutesBetween($now, $due);

            return $remaining <= $target * self::WARN_WHEN_REMAINING ? 'warning' : 'ok';
        }

        return 'ok';
    }

    private function policy(int $id): ?TicketSlaPolicy
    {
        return $this->policies[$id] ??= TicketSlaPolicy::find($id);
    }
}
