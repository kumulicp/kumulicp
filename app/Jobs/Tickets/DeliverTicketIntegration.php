<?php

namespace App\Jobs\Tickets;

use App\Services\Tickets\Integrations\DeliveryRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one ticket event to one integration. The delivery record, not the
 * queue, counts attempts and decides when to stop, so the job simply asks the
 * runner and is put back for the time it names.
 */
class DeliverTicketIntegration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Unlimited: DeliveryRunner gives up after the configured number of attempts. */
    public int $tries = 0;

    public function __construct(public int $deliveryId) {}

    public function handle(DeliveryRunner $runner): void
    {
        $wait = $runner->run($this->deliveryId);

        // A safety stop in case a queue driver re-runs released jobs immediately
        if ($wait !== null && $this->attempts() < 100) {
            $this->release($wait);
        }
    }
}
