<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\Updates\AspectInputs;
use App\Updates\Fingerprint;
use Illuminate\Support\Arr;

/**
 * The configurations the app is given through its update_settings job: the
 * plan's settings, the configurations that name this aspect and the features
 * that don't name another (see AspectInputs), and the instance's own choices.
 */
class SettingsAspect extends Aspect
{
    public function key(): string
    {
        return 'settings';
    }

    public function label(): string
    {
        return __('admin.update_aspects.settings');
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources();
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        $plan = $app_instance->plan;

        return Fingerprint::of([
            'version_id' => $app_instance->version_id,
            'plan' => $plan ? ['id' => $plan->id, 'settings' => AspectInputs::planSettings($app_instance, $this->key())] : null,
            'instance' => [
                'configurations' => AspectInputs::configurations($app_instance, (array) $app_instance->setting('configurations'), $this->key()),
                'override' => AspectInputs::configurations($app_instance, Arr::except((array) $app_instance->setting('override', []), ['pvc']), $this->key()),
            ],
            'features' => AspectInputs::features($app_instance, $this->key()),
        ]);
    }
}
