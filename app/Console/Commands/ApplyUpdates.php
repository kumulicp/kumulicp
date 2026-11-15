<?php

namespace App\Console\Commands;

use App\Actions\Apps\MassReconcile;
use App\Support\Facades\Action;
use App\Updates\PendingUpdates;
use App\Updates\Reconcile;
use Illuminate\Console\Command;

/**
 * Brings the app instances that are out of date up to date, a few first and then
 * the rest in batches (see MassReconcile). --dry-run says what it would do.
 */
class ApplyUpdates extends Command
{
    protected $signature = 'updates:apply
        {--aspect=* : Only what is out of date for this aspect (repeat for more)}
        {--app= : Only the instances of this app (slug)}
        {--canary=1 : How many are updated first}
        {--batch=10 : How many are updated at a time after that}
        {--dry-run : Say what would be updated, and update nothing}';

    protected $description = 'Bring the app instances that are out of date up to date';

    public function handle(PendingUpdates $pending, Reconcile $reconcile): int
    {
        $aspects = array_values(array_filter((array) $this->option('aspect')));

        $states = $pending->query()
            ->when($aspects !== [], fn ($query) => $query->whereIn('aspect', $aspects))
            ->when($this->option('app'), fn ($query, $slug) => $query->whereHas('app_instance.application', fn ($application) => $application->where('slug', $slug)))
            ->get(['app_instance_id', 'aspect']);

        $instance_ids = $states->pluck('app_instance_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $aspects = $states->pluck('aspect')->unique()->values()->all();

        $canary = max(0, (int) $this->option('canary'));
        $batch = max(1, (int) $this->option('batch'));

        $preview = $reconcile->preview($instance_ids, $aspects, $canary, $batch);

        if ($preview['instances'] === 0) {
            $this->info('Nothing to update.');

            return self::SUCCESS;
        }

        $this->info("{$preview['instances']} app instances, in ".count($preview['waves']).' waves of '.implode(', ', $preview['waves']).'.');
        $this->table(
            ['Step', 'App instances', 'Disruptive'],
            array_map(fn (array $step) => [$step['label'], $step['instances'], $step['disruptive'] ? 'yes' : 'no'], $preview['steps'])
        );

        foreach ($preview['skipped'] as $reason => $count) {
            $this->line("Left out, {$reason}: {$count}");
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        Action::execute(new MassReconcile($instance_ids, $aspects, $canary, $batch));
        $this->info('Started. Follow it under Server > Tasks.');

        return self::SUCCESS;
    }
}
