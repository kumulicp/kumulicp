<?php

namespace App\Updates;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Updates\Aspects\Aspect;
use App\Updates\Aspects\FeatureAspect;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The aspects an app instance has: the ones every app has, the ones its
 * profile adds, and one for each feature that brings an action.
 */
class AspectRegistry
{
    /** @var array<string, Aspect> */
    private array $aspects = [];

    public function register(Aspect $aspect): static
    {
        $this->aspects[$aspect->key()] = $aspect;

        return $this;
    }

    /**
     * What to call an aspect where no instance is at hand (a list of what is
     * out of date). An aspect that only one app has is named after its key.
     */
    public function label(string $key): string
    {
        if (isset($this->aspects[$key])) {
            return $this->aspects[$key]->label();
        }

        return Str::headline(Str::after($key, 'feature.'));
    }

    /**
     * @return Collection<string, Aspect>
     */
    public function for(AppInstance $app_instance): Collection
    {
        $aspects = collect($this->aspects);

        foreach (Application::profile($app_instance->application->slug)->aspects() as $aspect) {
            $aspects->put($aspect->key(), $aspect);
        }

        foreach (Application::features($app_instance->application->slug, $app_instance) as $feature) {
            if ($aspect = FeatureAspect::for($feature)) {
                $aspects->put($aspect->key(), $aspect);
            }
        }

        return $aspects->filter(fn (Aspect $aspect) => $aspect->appliesTo($app_instance));
    }
}
