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

    /**
     * @param  bool  $with_images  false gives the fingerprint from before the images were part of it
     */
    public function fingerprint(AppInstance $app_instance, bool $with_images = true): string
    {
        $version = $app_instance->version;
        $plan = $app_instance->plan;

        $payload = [
            'version' => $version ? [
                'id' => $version->id,
                'name' => $version->name,
                // update_check changes daily and says nothing about the version itself
                'settings' => $version->deploymentSettings(),
                'image_registry_id' => $version->image_registry_id,
                'helm_repo_secret_id' => $version->chartRepoSecretId(),
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
        ];

        // Only the images that are deployed, and left out when there is nothing to add,
        // so an instance whose version has only the image its settings describe isn't
        // out of date for a change that was made to the fingerprint
        if ($with_images && $version && $images = $version->imageInputs(deployed_only: true)) {
            $payload['images'] = $images;
        }

        return Fingerprint::of($payload);
    }
}
