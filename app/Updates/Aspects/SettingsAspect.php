<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\Updates\Fingerprint;

/**
 * The configurations the app is given through its update_settings job: the
 * plan's settings and features and the instance's own choices.
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
            'plan' => $plan ? ['id' => $plan->id, 'settings' => $plan->settings, 'features' => $plan->features] : null,
            'instance' => [
                'configurations' => $app_instance->setting('configurations'),
                'features' => $app_instance->setting('features'),
            ],
        ]);
    }
}
