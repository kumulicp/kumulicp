<?php

namespace App\Console\Commands;

use App\AppInstance;
use App\AppPlan;
use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandaloneChart;
use App\Integrations\Applications\HelmForge\HelmForgeChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\GenericChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\WordpressChart;
use App\Support\Facades\Application;
use App\Support\Volumes\VolumeSettingsMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Moves what the plans' and the instances' settings keep about volumes into `volumes.<name>.*`
 * (docs/volumes-and-settings-plan.md, "The migration"): see VolumeSettingsMigrator for what and how.
 *
 * It copies and does not delete, never writes over a volume setting that is already there, and so can be run
 * again. It writes the settings directly and not through the models, so that nothing that listens for a saved
 * instance (a pending change, an update) runs because of it.
 */
class MigrateVolumes extends Command
{
    protected $signature = 'volumes:migrate
        {--dry-run : Say what would be written, and write nothing}';

    protected $description = 'Copy the volume settings of the plans and instances into volumes.<name>, from the places each chart kept them';

    public function handle(): int
    {
        $dry_run = (bool) $this->option('dry-run');
        $counts = ['plans' => 0, 'instances' => 0, 'skipped' => 0, 'unchanged' => 0];
        $notes = [];

        // What each plan's volumes are once it is migrated, for the instances that follow it
        $plan_volumes = [];

        foreach (AppPlan::query()->lazyById() as $plan) {
            $layout = $this->layout($plan);

            if ($layout === 'generic') {
                $counts['skipped']++;

                continue;
            }

            $result = (new VolumeSettingsMigrator($layout))->plan($plan->settings ?? []);
            $plan_volumes[$plan->id] = $this->merged($plan->settings ?? [], $result['volumes'])['volumes'] ?? [];

            $this->write('app_plans', $plan->id, $plan->settings ?? [], $result['volumes'], $dry_run, $counts, 'plans');
            $this->collect("plan {$plan->id} ({$plan->name})", $result['notes'], $notes);
        }

        foreach (AppInstance::query()->with('plan')->lazyById() as $instance) {
            $layout = $this->layout($instance);

            if ($layout === 'generic') {
                $counts['skipped']++;

                continue;
            }

            $result = (new VolumeSettingsMigrator($layout))->instance($instance->settings ?? [], $plan_volumes[$instance->plan_id] ?? []);

            $this->write('app_instances', $instance->id, $instance->settings ?? [], $result['volumes'], $dry_run, $counts, 'instances');
            $this->collect("instance {$instance->id} ({$instance->label}, organization {$instance->organization_id})", $result['notes'], $notes);
        }

        $this->components->info(($dry_run ? 'Would write ' : 'Wrote ')."volumes to {$counts['plans']} plans and {$counts['instances']} instances; {$counts['unchanged']} had nothing to add; {$counts['skipped']} are the generic app's, which is migrated on its own.");

        foreach ($notes as $note) {
            $this->components->warn($note);
        }

        return self::SUCCESS;
    }

    /**
     * The layout of the chart that deploys the app: where it kept its volume.
     */
    private function layout(AppPlan|AppInstance $subject): string
    {
        foreach ((array) Application::profileFor($subject)->chart() as $chart) {
            if (! is_string($chart)) {
                continue;
            }

            return match (true) {
                is_a($chart, NextcloudChart::class, true) => 'nextcloud',
                is_a($chart, WordpressChart::class, true) => 'wordpress',
                is_a($chart, CiviCRMStandaloneChart::class, true) => 'civicrm',
                is_a($chart, HelmForgeChart::class, true) => 'catalog',
                is_a($chart, GenericChart::class, true) => 'generic',
                default => 'other',
            };
        }

        return 'other';
    }

    /**
     * The settings with the volumes added, except where one is already set.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $volumes
     * @return array<string, mixed>
     */
    private function merged(array $settings, array $volumes): array
    {
        foreach ($volumes as $name => $fields) {
            foreach ($fields as $field => $value) {
                if (! Arr::has($settings, "volumes.{$name}.{$field}")) {
                    Arr::set($settings, "volumes.{$name}.{$field}", $value);
                }
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $volumes
     * @param  array<string, int>  $counts
     */
    private function write(string $table, int $id, array $settings, array $volumes, bool $dry_run, array &$counts, string $kind): void
    {
        $merged = $this->merged($settings, $volumes);

        if ($merged === $settings) {
            $counts['unchanged']++;

            return;
        }

        $counts[$kind]++;

        if (! $dry_run) {
            DB::table($table)->where('id', $id)->update(['settings' => json_encode($merged)]);
        }
    }

    /**
     * @param  array<int, string>  $found
     * @param  array<int, string>  $notes
     */
    private function collect(string $subject, array $found, array &$notes): void
    {
        foreach ($found as $note) {
            $notes[] = "{$subject}: {$note}";
        }
    }
}
