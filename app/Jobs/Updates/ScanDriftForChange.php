<?php

namespace App\Jobs\Updates;

use App\DefinitionChange;
use App\Updates\Detection\ImpactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Looks at the instances and organizations an edit can affect, in batches, for what it put out of date */
class ScanDriftForChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $change_id) {}

    public function handle(ImpactResolver $impact): void
    {
        $change = DefinitionChange::find($this->change_id);

        if (! $change) {
            return;
        }

        $impact->targets($change->subject_type, $change->subject_id, $change->application_id)
            ?->orderBy('id')->pluck('id')->chunk(ScanInstancesForDrift::BATCH)->each(
                fn ($ids) => ScanInstancesForDrift::dispatch($ids->values()->all(), $change->id)
            );

        $impact->organizations($change->subject_type, $change->subject_id)
            ?->orderBy('id')->pluck('id')->chunk(ScanOrganizationsForDrift::BATCH)->each(
                fn ($ids) => ScanOrganizationsForDrift::dispatch($ids->values()->all(), $change->id)
            );
    }
}
