<?php

namespace App\Console\Commands;

use App\AppInstance;
use App\Updates\OrganizationDrift;
use App\Updates\UpdateDrift;
use Illuminate\Console\Command;

/**
 * Takes the app instances as up to date for the aspects that have nothing
 * stored yet, so that only changes made from now on show as out of date.
 * Aspects that already have something stored are left alone.
 */
class BaselineUpdateState extends Command
{
    protected $signature = 'updates:baseline {--app= : Only the instances of this app (slug)}';

    protected $description = 'Take the app instances as up to date, for the aspects nothing is stored for yet';

    public function handle(UpdateDrift $drift, OrganizationDrift $organization_drift): int
    {
        $stored = 0;
        $instances = 0;

        AppInstance::query()
            ->when($this->option('app'), fn ($query, $slug) => $query->whereHas('application', fn ($application) => $application->where('slug', $slug)))
            ->with(['application', 'version', 'plan'])
            ->chunkById(100, function ($chunk) use ($drift, &$stored, &$instances) {
                foreach ($chunk as $app_instance) {
                    try {
                        $count = count($drift->baseline($app_instance));
                    } catch (\Throwable $e) {
                        $this->warn("{$app_instance->label} (#{$app_instance->id}): {$e->getMessage()}");

                        continue;
                    }

                    $stored += $count;
                    $instances += $count > 0 ? 1 : 0;
                }
            });

        $this->info("Stored {$stored} aspects on {$instances} app instances.");

        // The subscriptions of organizations too, unless it was just one app
        if (! $this->option('app')) {
            $organizations = 0;

            $organization_drift->scannable()->chunkById(50, function ($chunk) use ($organization_drift, &$organizations) {
                foreach ($chunk as $organization) {
                    try {
                        $organizations += $organization_drift->baseline($organization) ? 1 : 0;
                    } catch (\Throwable $e) {
                        $this->warn("{$organization->name} (#{$organization->id}): {$e->getMessage()}");
                    }
                }
            });

            $this->info("Stored the subscription of {$organizations} organizations.");
        }

        return self::SUCCESS;
    }
}
