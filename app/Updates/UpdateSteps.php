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
     * The steps this app has, in the order they can run: each after the ones it
     * says it follows.
     *
     * @return Collection<string, UpdateStep>
     */
    public function for(AppInstance $app_instance): Collection
    {
        $steps = collect($this->steps);

        foreach (Application::profile($app_instance->application->slug)->updateSteps() as $step) {
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
     * an update, the app was upgraded since it last ran, or what it brings up to
     * date has changed (out_of_date).
     *
     * @return array<int, array{key: string, label: string, description: string, checked: bool, out_of_date: bool}>
     */
    public function options(AppInstance $app_instance): array
    {
        $recommended = $this->recommended($app_instance);
        $out_of_date = app(UpdateDrift::class)->stepsToRun($app_instance);

        return $this->for($app_instance)->map(fn (UpdateStep $step, string $key) => [
            'key' => $key,
            'label' => $step->label(),
            'description' => $step->description(),
            'checked' => $step->checkedByDefault() || in_array($key, $recommended, true) || in_array($key, $out_of_date, true),
            'out_of_date' => in_array($key, $out_of_date, true),
        ])->values()->all();
    }

    /** @return array<int, string> */
    public function recommended(AppInstance $app_instance): array
    {
        return (array) $app_instance->setting('update.recommended', []);
    }

    /** The app has a new version: the steps that may have to catch up are ticked next time */
    public function recommendAfterUpgrade(AppInstance $app_instance): void
    {
        $keys = $this->for($app_instance)
            ->filter(fn (UpdateStep $step) => $step->afterUpgrade($app_instance))
            ->keys()
            ->all();

        $app_instance->updateSetting('update.recommended', array_values(array_unique(array_merge($this->recommended($app_instance), $keys))));
    }

    public function forget(AppInstance $app_instance, string $key): void
    {
        $recommended = $this->recommended($app_instance);

        if (in_array($key, $recommended, true)) {
            $app_instance->updateSetting('update.recommended', array_values(array_diff($recommended, [$key])));
        }
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
