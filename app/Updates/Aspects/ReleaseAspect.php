<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\Updates\AspectInputs;
use App\Updates\Fingerprint;
use Illuminate\Support\Arr;

/**
 * What the app is deployed as on its web server: the version's chart and
 * image, the servers and plan it runs with, and the instance's own settings.
 * Of the configurations, the ones that don't name another aspect (see
 * AspectInputs).
 */
class ReleaseAspect extends Aspect
{
    public function key(): string
    {
        return 'release';
    }

    public function label(): string
    {
        return __('admin.update_aspects.release');
    }

    // A shared child without a site of its own is covered by the hub's release
    public function appliesTo(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources();
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        $version = $app_instance->version;
        $plan = $app_instance->plan;

        return Fingerprint::of([
            'version' => $version ? [
                'id' => $version->id,
                'name' => $version->name,
                // update_check changes daily and says nothing about the version itself
                'settings' => Arr::except($version->settings ?? [], ['update_check']),
                'image_registry_id' => $version->image_registry_id,
                'helm_repo_secret_id' => $version->helm_repo_secret_id,
            ] : null,
            'plan' => $plan ? [
                'id' => $plan->id,
                'settings' => AspectInputs::planSettings($app_instance, $this->key()),
            ] : null,
            'servers' => [
                'web' => $app_instance->web_server_id,
                'database' => $app_instance->database_server_id,
                'plan_web' => $plan?->web_server_id,
                'plan_database' => $plan?->database_server_id,
            ],
            'instance' => [
                'configurations' => AspectInputs::configurations($app_instance, (array) $app_instance->setting('configurations'), $this->key()),
                // override.pvc is written back by the release itself
                'override' => AspectInputs::configurations($app_instance, Arr::except((array) $app_instance->setting('override', []), ['pvc']), $this->key()),
            ],
            'features' => AspectInputs::features($app_instance, $this->key()),
        ]);
    }
}
