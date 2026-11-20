<?php

namespace App\Support\VersionTesting;

use App\AppPlan;
use App\AppVersion;
use App\Updates\Fingerprint;

/**
 * A hash of everything a version test exercises: the version's own
 * configuration and the plans it was tested with (all the plans a customer could
 * install it with, or the one plan of a shared app). A test only
 * counts while the fingerprint it was run against still matches.
 */
class VersionFingerprint
{
    /**
     * @param  bool  $with_images  false gives the fingerprint from before the images were part of it
     */
    public function for(AppVersion $version, bool $with_images = true): string
    {
        $plans = AppPlan::where('application_id', $version->application_id)
            ->where('archive', 0)
            ->visible()
            ->orderBy('id')
            ->get()
            ->map(fn (AppPlan $plan) => $this->planPayload($plan))
            ->all();

        return $this->hash($version, $plans, $with_images);
    }

    /**
     * The fingerprint of a version tested on one plan only, which is how a
     * shared app's own plan is tested. It covers that plan's servers, so
     * changing them after the test counts as a change.
     */
    public function forPlan(AppVersion $version, AppPlan $plan, bool $with_images = true): string
    {
        return $this->hash($version, [$this->planPayload($plan)], $with_images);
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(AppPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'settings' => $plan->settings,
            'features' => $plan->features,
            'web_server_id' => $plan->web_server_id,
            'database_server_id' => $plan->database_server_id,
            'sso_server_id' => $plan->sso_server_id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     */
    private function hash(AppVersion $version, array $plans, bool $with_images): string
    {
        $payload = [
            'version' => [
                'id' => $version->id,
                'name' => $version->name,
                // update_check changes daily and says nothing about the version itself
                'settings' => $version->deploymentSettings(),
                'roles' => $version->roles,
                'admin_path' => $version->admin_path,
                'image_registry_id' => $version->image_registry_id,
                'helm_repo_secret_id' => $version->chartRepoSecretId(),
            ],
            'plans' => $plans,
        ];

        // Left out when there is nothing to add, so a version with only the image its
        // settings describe keeps the fingerprint (and the passed tests) it had
        if ($with_images && $images = $version->imageInputs()) {
            $payload['images'] = $images;
        }

        return Fingerprint::of($payload);
    }
}
