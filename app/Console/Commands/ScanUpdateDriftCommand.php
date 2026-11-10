<?php

namespace App\Console\Commands;

use App\Console\Calls\ScanUpdateDrift;
use App\UpdateState;
use Illuminate\Console\Command;

/** Looks at the app instances for what has gone out of date, and lists it */
class ScanUpdateDriftCommand extends Command
{
    protected $signature = 'updates:scan {--app= : Only the instances of this app (slug)}';

    protected $description = 'Look at the app instances for what has gone out of date';

    public function handle(): int
    {
        (new ScanUpdateDrift($this->option('app') ?: null, now: true))();

        $rows = UpdateState::drifted()
            ->with('app_instance.organization')
            ->when($this->option('app'), fn ($query, $slug) => $query->whereHas('app_instance.application', fn ($application) => $application->where('slug', $slug)))
            ->orderBy('app_instance_id')
            ->get()
            ->map(fn (UpdateState $state) => [
                $state->app_instance->organization?->name,
                $state->app_instance->label,
                $state->aspect,
                $state->drift_detected_at?->toDateTimeString(),
            ]);

        $rows->isEmpty()
            ? $this->info('Everything is up to date.')
            : $this->table(['Organization', 'App', 'Out of date', 'Since'], $rows->all());

        return self::SUCCESS;
    }
}
