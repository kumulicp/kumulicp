<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Updates\AspectInputs;
use App\Updates\Fingerprint;
use App\Updates\Steps\FeatureActionStep;

/**
 * One feature of the app that brings an action: whether the plan and the
 * instance have it on, and its settings. Goes with FeatureActionStep.
 */
class FeatureAspect extends Aspect
{
    public function __construct(private readonly string $feature, private readonly string $title) {}

    public static function for(AppFeature $feature): ?self
    {
        $step = FeatureActionStep::for($feature);

        return $step ? new self($feature->name, $feature->label ?: $feature->name) : null;
    }

    public static function keyFor(string $feature): string
    {
        return 'feature.'.$feature;
    }

    public function key(): string
    {
        return self::keyFor($this->feature);
    }

    public function label(): string
    {
        return $this->title;
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        return Fingerprint::of([
            // A new version may have customizations the action applies
            'version_id' => $app_instance->version_id,
            'plan' => AspectInputs::planFeature($app_instance->plan, $this->feature),
            'instance' => $app_instance->setting('features.'.$this->feature),
        ]);
    }
}
