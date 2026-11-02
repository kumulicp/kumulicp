<?php

namespace App\Console\Calls;

use App\Jobs\Tickets\DeliverTicketIntegration;
use App\TicketDelivery;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * Housekeeping for integration deliveries:
 *  - put back on the queue any delivery that has been waiting suspiciously long
 *    (a lost job, a restarted worker), so nothing is stuck for good
 *  - forget old finished deliveries
 */
class MaintainTicketDeliveries
{
    public function __invoke(): void
    {
        if (Toggle::inactive('tickets')) {
            return;
        }

        // The longest normal wait between attempts is four hours
        TicketDelivery::where('status', TicketDelivery::PENDING)
            ->where(function ($query) {
                $query->where('last_attempt_at', '<', now()->subHours(5))
                    ->orWhere(fn ($query) => $query->whereNull('last_attempt_at')->where('created_at', '<', now()->subMinutes(30)));
            })
            ->each(fn (TicketDelivery $delivery) => DeliverTicketIntegration::dispatch($delivery->id));

        TicketDelivery::where('status', '!=', TicketDelivery::PENDING)
            ->where('created_at', '<', now()->subDays(config('tickets.integrations.retention_days')))
            ->delete();
    }
}
