<?php

namespace App\Jobs\Updates;

use App\Organization;
use App\Updates\OrganizationDrift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Compares what the subscription aspects of some organizations work out now with
 * what was applied (OrganizationDrift::refresh()). Changes nothing on the
 * organizations itself.
 */
class ScanOrganizationsForDrift implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Working out a subscription is more than looking at an instance
    public const BATCH = 10;

    /**
     * @param  array<int, int>  $organization_ids
     * @param  int|null  $change_id  The edit that made these organizations worth looking at, if one did
     */
    public function __construct(public array $organization_ids, public ?int $change_id = null) {}

    public function handle(OrganizationDrift $drift): void
    {
        foreach (Organization::whereIn('id', $this->organization_ids)->get() as $organization) {
            // One organization that can't be worked out mustn't stop the others being looked at
            try {
                $drift->refresh($organization, $this->change_id);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
