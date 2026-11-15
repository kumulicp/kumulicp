<?php

namespace App\Updates;

use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\Support\Facades\Action;
use App\Task;
use Illuminate\Support\Collection;

/**
 * Brings app instances up to date for what is out of date, and nothing else: the
 * steps that go with what changed (UpdateDrift::stepsToRun()), run as an
 * ApplicationUpdate.
 */
class Reconcile
{
    public const NOT_ACTIVE = 'not_active';

    public const NOTHING_TO_DO = 'nothing_to_do';

    public function __construct(private readonly UpdateDrift $drift, private readonly UpdateSteps $steps) {}

    /**
     * What updating the instance would run, or why it wouldn't: an app that is
     * being updated, upgraded, removed... is left alone.
     *
     * @param  array<int, string>|null  $aspects  Just for these
     * @return array{steps: array<int, string>, skipped: string|null}
     */
    public function plan(AppInstance $app_instance, ?array $aspects = null): array
    {
        if ($app_instance->status !== 'active') {
            return ['steps' => [], 'skipped' => self::NOT_ACTIVE];
        }

        $steps = $this->drift->stepsToRun($app_instance, $aspects);

        return ['steps' => $steps, 'skipped' => $steps === [] ? self::NOTHING_TO_DO : null];
    }

    /**
     * Starts updating the instance. Null when there is nothing to run, or an
     * update of it is already on its way.
     *
     * @param  array<int, string>|null  $aspects  Just for these
     */
    public function start(AppInstance $app_instance, ?array $aspects = null, ?Task $parent = null): ?Task
    {
        $plan = $this->plan($app_instance, $aspects);

        if ($plan['steps'] === []) {
            return null;
        }

        return Action::execute(new ApplicationUpdate($app_instance, $plan['steps']), $parent, true);
    }

    /**
     * What updating the instances would do, to say so before it is started.
     *
     * @param  array<int, int>  $instance_ids
     * @param  array<int, string>|null  $aspects
     * @return array{instances: int, skipped: array<string, int>, steps: array<int, array{key: string, label: string, disruptive: bool, instances: int}>, disruptive: bool, waves: array<int, int>}
     */
    public function preview(array $instance_ids, ?array $aspects = null, int $canary = 1, int $batch = 10): array
    {
        $eligible = collect();
        $skipped = [];
        $steps = [];

        foreach (AppInstance::whereIn('id', $instance_ids)->get() as $app_instance) {
            $plan = $this->plan($app_instance, $aspects);

            if ($plan['skipped']) {
                $skipped[$plan['skipped']] = ($skipped[$plan['skipped']] ?? 0) + 1;

                continue;
            }

            $eligible->push($app_instance);

            foreach ($plan['steps'] as $key) {
                $step = $this->steps->find($app_instance, $key);
                $steps[$key] ??= ['key' => $key, 'label' => $step?->label() ?? $key, 'disruptive' => (bool) $step?->disruptive(), 'instances' => 0];
                $steps[$key]['instances']++;
            }
        }

        return [
            'instances' => $eligible->count(),
            'skipped' => $skipped,
            'steps' => array_values($steps),
            'disruptive' => collect($steps)->contains('disruptive', true),
            'waves' => array_map('count', $this->waves($eligible, $canary, $batch)),
        ];
    }

    /**
     * The order a lot of instances are updated in: a few first, then the rest in
     * batches, each when the one before it has gone well. The few are picked from
     * as many different apps as there are, so each app's own update is tried before
     * it is done to the rest.
     *
     * @param  Collection<int, AppInstance>  $instances
     * @return array<int, array<int, int>> instance ids by wave
     */
    public function waves(Collection $instances, int $canary, int $batch): array
    {
        $instances = $instances->sortBy('id')->values();
        $groups = $instances->groupBy('application_id')->values();

        $canaries = [];
        for ($round = 0; count($canaries) < $canary; $round++) {
            $added = false;

            foreach ($groups as $group) {
                if (count($canaries) < $canary && isset($group[$round])) {
                    $canaries[] = $group[$round]->id;
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        $waves = $canaries === [] ? [] : [$canaries];

        foreach ($instances->pluck('id')->diff($canaries)->values()->chunk(max(1, $batch)) as $chunk) {
            $waves[] = $chunk->values()->all();
        }

        return $waves;
    }
}
