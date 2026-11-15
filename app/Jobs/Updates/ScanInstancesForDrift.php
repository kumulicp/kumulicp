<?php

namespace App\Jobs\Updates;

use App\AppInstance;
use App\Updates\AutoUpdates;
use App\Updates\UpdateDrift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Compares what the aspects of some app instances work out now with what was
 * applied (UpdateDrift::refresh()). Changes nothing on the instances.
 */
class ScanInstancesForDrift implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BATCH = 50;

    /**
     * @param  array<int, int>  $instance_ids
     * @param  int|null  $change_id  The edit that made these instances worth looking at, if one did
     */
    public function __construct(public array $instance_ids, public ?int $change_id = null) {}

    public function handle(UpdateDrift $drift, AutoUpdates $auto_updates): void
    {
        $instances = AppInstance::whereIn('id', $this->instance_ids)->with(['application', 'version', 'plan'])->get();

        foreach ($instances as $instance) {
            // One instance that can't be worked out mustn't stop the others being looked at
            try {
                $drifted = $drift->refresh($instance, $this->change_id);

                // What an admin has said is updated on its own, if anything (see UpdatePolicy)
                if ($drifted !== []) {
                    $auto_updates->run($instance, $drifted);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
