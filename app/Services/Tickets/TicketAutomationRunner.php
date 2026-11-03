<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Ticket;
use Throwable;

/**
 * Applies the automation rules to existing tickets. Run every few minutes by
 * the scheduler. One ticket failing never stops the others.
 */
class TicketAutomationRunner
{
    public function __construct(
        private TicketService $tickets,
        private TicketAutomation $automation,
        private TicketPurger $purger,
    ) {}

    /**
     * @return array<string, int> What was done, by kind
     */
    public function run(): array
    {
        $rules = $this->automation->settings();

        return [
            'assigned' => $this->autoAssign($rules['auto_assign']),
            'reminded' => $this->remind((int) $rules['reminder_days']),
            'resolved' => $this->autoResolve((int) $rules['auto_resolve_days']),
            'closed' => $this->autoClose((int) $rules['auto_close_days']),
            'purged' => $this->purger->purgeClosedOlderThan((int) $rules['retention_days']),
        ] + $this->tickets->sweepSla();
    }

    /**
     * Tickets that came in while nobody was available get someone now.
     */
    private function autoAssign(string $mode): int
    {
        if ($mode === 'off') {
            return 0;
        }

        $count = 0;

        Ticket::whereIn('status', TicketStatus::openValues())
            ->whereNull('support_assignee_id')
            ->whereNull('merged_into_id')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (Ticket $ticket) use (&$count) {
                $this->guard(function () use ($ticket, &$count) {
                    if ($assignee = $this->automation->pickAssignee()) {
                        $this->tickets->assign($ticket, TicketService::SUPPORT, $assignee, null);
                        $count++;
                    }
                });
            });

        return $count;
    }

    private function remind(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $count = 0;

        Ticket::where('status', TicketStatus::PENDING_CUSTOMER->value)
            ->whereNull('reminder_sent_at')
            ->whereNull('merged_into_id')
            ->where('last_activity_at', '<', now()->subDays($days))
            ->each(function (Ticket $ticket) use (&$count) {
                $this->guard(function () use ($ticket, &$count) {
                    $this->tickets->sendReminder($ticket);
                    $count++;
                });
            });

        return $count;
    }

    private function autoResolve(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $count = 0;

        Ticket::where('status', TicketStatus::PENDING_CUSTOMER->value)
            ->whereNull('merged_into_id')
            ->where('last_activity_at', '<', now()->subDays($days))
            ->each(function (Ticket $ticket) use (&$count) {
                $this->guard(function () use ($ticket, &$count) {
                    $this->tickets->changeStatus($ticket, null, TicketStatus::RESOLVED, true);
                    $count++;
                });
            });

        return $count;
    }

    private function autoClose(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $count = 0;

        Ticket::where('status', TicketStatus::RESOLVED->value)
            ->whereNull('merged_into_id')
            ->where('resolved_at', '<', now()->subDays($days))
            ->each(function (Ticket $ticket) use (&$count) {
                $this->guard(function () use ($ticket, &$count) {
                    $this->tickets->changeStatus($ticket, null, TicketStatus::CLOSED, true);
                    $count++;
                });
            });

        return $count;
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
