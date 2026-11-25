<?php

namespace App\Updates;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Updates\Steps\FeatureActionStep;
use Illuminate\Support\Collection;

/**
 * The steps an app's update can run: the ones every app has, the ones its
 * profile adds, and one for each feature that brings an action.
 */
class UpdateSteps
{
    /** @var array<string, UpdateStep> */
    private array $steps = [];

    public function register(UpdateStep $step): static
    {
        $this->steps[$step->key()] = $step;

        return $this;
    }

    /**
     * The steps every app has, whether this app has them or not.
     *
     * @return array<string, UpdateStep>
     */
    public function registered(): array
    {
        return $this->steps;
    }

    /**
     * The steps this app has, in the order they can run: each after the ones it
     * says it follows.
     *
     * @return Collection<string, UpdateStep>
     */
    public function for(AppInstance $app_instance): Collection
    {
        $steps = collect($this->steps);

        foreach (Application::profileFor($app_instance)->updateSteps() as $step) {
            $steps->put($step->key(), $step);
        }

        foreach (Application::features($app_instance->application->slug, $app_instance) as $feature) {
            if ($step = FeatureActionStep::for($feature)) {
                $steps->put($step->key(), $step);
            }
        }

        return $this->ordered($steps->filter(fn (UpdateStep $step) => $step->appliesTo($app_instance)));
    }

    public function find(AppInstance $app_instance, string $key): ?UpdateStep
    {
        return $this->for($app_instance)->get($key);
    }

    /**
     * What the update list offers: every step, ticked if it normally goes with
     * an update or what it brings up to date has changed (out_of_date), which
     * includes what a new version may need (see aspectsAfterUpgrade()).
     *
     * @return array<int, array{key: string, label: string, description: string, checked: bool, out_of_date: bool}>
     */
    public function options(AppInstance $app_instance): array
    {
        $out_of_date = app(UpdateDrift::class)->stepsToRun($app_instance);

        return $this->for($app_instance)->map(fn (UpdateStep $step, string $key) => [
            'key' => $key,
            'label' => $step->label(),
            'description' => $step->description(),
            'checked' => $step->checkedByDefault() || in_array($key, $out_of_date, true),
            'out_of_date' => in_array($key, $out_of_date, true),
        ])->values()->all();
    }

    /**
     * The aspects that a new version may put out of date without anything the
     * fingerprints cover changing, because the steps for them say so (see
     * UpdateStep::afterUpgrade()).
     *
     * @return array<int, string>
     */
    public function aspectsAfterUpgrade(AppInstance $app_instance): array
    {
        return $this->for($app_instance)
            ->filter(fn (UpdateStep $step) => $step->afterUpgrade($app_instance))
            ->flatMap(fn (UpdateStep $step) => $step->aspects())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Steps in an order that has each one after the steps it follows. The
     * order they were added in otherwise; a step that follows one that isn't
     * there is not held up by it.
     *
     * @param  Collection<string, UpdateStep>  $steps
     * @return Collection<string, UpdateStep>
     */
    private function ordered(Collection $steps): Collection
    {
        $ordered = collect();
        $visiting = [];

        $visit = function (string $key) use (&$visit, &$ordered, &$visiting, $steps) {
            if ($ordered->has($key) || isset($visiting[$key])) {
                return;
            }

            $visiting[$key] = true;
            foreach ($steps->get($key)->after() as $earlier) {
                if ($steps->has($earlier)) {
                    $visit($earlier);
                }
            }
            unset($visiting[$key]);

            $ordered->put($key, $steps->get($key));
        };

        foreach ($steps->keys() as $key) {
            $visit($key);
        }

        return $ordered;
    }
}
