<?php

namespace App\Updates;

use App\AppInstance;
use App\UpdateState;
use Illuminate\Support\Collection;

/**
 * Tells what an app instance is out of date for, by comparing what its aspects
 * work out now with what was stored the last time a step for them ran.
 *
 * An aspect with nothing stored yet isn't out of date, it is unknown: an
 * instance that was running before this existed isn't flagged for everything.
 * It starts being compared once a step for it has run, or baseline() has
 * stored where it stands.
 */
class UpdateDrift
{
    public function __construct(private readonly AspectRegistry $aspects, private readonly UpdateSteps $steps) {}

    /**
     * What the aspects of this instance work out now.
     *
     * @param  array<int, string>|null  $only  Just these aspects
     * @return array<string, string> fingerprints by aspect key
     */
    public function current(AppInstance $app_instance, ?array $only = null): array
    {
        return $this->aspects->for($app_instance)
            ->when($only !== null, fn (Collection $aspects) => $aspects->only($only))
            ->map(fn ($aspect) => $aspect->fingerprint($app_instance))
            ->all();
    }

    /**
     * What was stored when a step last ran for each aspect.
     *
     * @return array<string, string> fingerprints by aspect key
     */
    public function applied(AppInstance $app_instance): array
    {
        return UpdateState::where('app_instance_id', $app_instance->id)->pluck('fingerprint', 'aspect')->all();
    }

    /**
     * The aspects that changed since a step last ran for them.
     *
     * @return array<int, string>
     */
    public function drift(AppInstance $app_instance): array
    {
        $applied = $this->applied($app_instance);

        $drifted = [];
        foreach ($this->current($app_instance) as $key => $fingerprint) {
            if (isset($applied[$key]) && $applied[$key] !== $fingerprint) {
                $drifted[] = $key;
            }
        }

        return $drifted;
    }

    /**
     * The aspects with nothing stored yet.
     *
     * @return array<int, string>
     */
    public function unknown(AppInstance $app_instance): array
    {
        return array_values(array_diff(array_keys($this->current($app_instance)), array_keys($this->applied($app_instance))));
    }

    /**
     * The steps to run to bring the instance up to date: the ones that go with
     * an aspect that changed, and nothing else.
     *
     * @return array<int, string> step keys, in the order they run
     */
    public function stepsToRun(AppInstance $app_instance): array
    {
        $drifted = $this->drift($app_instance);

        return $this->steps->for($app_instance)
            ->filter(fn (UpdateStep $step) => array_intersect($step->aspects(), $drifted) !== [])
            ->keys()
            ->all();
    }

    /**
     * What the aspects of a step work out now, to store once the step has
     * run. Worked out before it starts: if something changes while it runs,
     * the next comparison finds the instance out of date again rather than
     * missing it.
     *
     * @return array<string, string>
     */
    public function forStep(AppInstance $app_instance, UpdateStep $step): array
    {
        return $step->aspects() === [] ? [] : $this->current($app_instance, $step->aspects());
    }

    /**
     * Stores what has been applied.
     *
     * @param  array<string, string>  $fingerprints  by aspect key
     */
    public function record(AppInstance $app_instance, array $fingerprints, ?int $task_id = null): void
    {
        foreach ($fingerprints as $aspect => $fingerprint) {
            UpdateState::updateOrCreate(
                ['app_instance_id' => $app_instance->id, 'aspect' => $aspect],
                ['fingerprint' => $fingerprint, 'applied_at' => now(), 'task_id' => $task_id],
            );
        }
    }

    /**
     * Takes where the instance stands as up to date for the aspects with
     * nothing stored yet. For an instance that is known to be in step with its
     * definitions, so only changes from now on show.
     *
     * @return array<int, string> the aspects stored
     */
    public function baseline(AppInstance $app_instance): array
    {
        $unknown = $this->unknown($app_instance);

        $this->record($app_instance, $this->current($app_instance, $unknown));

        return $unknown;
    }

    /**
     * Takes the aspects as up to date without running anything, because an
     * admin decided the change needs nothing done.
     *
     * @param  array<int, string>|null  $only  Just these aspects
     */
    public function dismiss(AppInstance $app_instance, ?array $only = null): void
    {
        $this->record($app_instance, $this->current($app_instance, $only));
    }
}
