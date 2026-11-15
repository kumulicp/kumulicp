<?php

namespace App\Support\VersionTesting;

use App\AppPlan;
use App\AppVersion;
use App\Updates\Fingerprint;
use Illuminate\Support\Arr;

/**
 * A hash of everything a version test exercises: the version's own
 * configuration and the plans it was tested with (all the plans a customer could
 * install it with, or the one plan of a shared app). A test only
 * counts while the fingerprint it was run against still matches.
 */
class VersionFingerprint
{
    public function for(AppVersion $version): string
    {
        $plans = AppPlan::where('application_id', $version->application_id)
            ->where('archive', 0)
            ->visible()
            ->orderBy('id')
            ->get()
            ->map(fn (AppPlan $plan) => $this->planPayload($plan))
            ->all();

        return $this->hash($version, $plans);
    }

    /**
     * The fingerprint of a version tested on one plan only, which is how a
     * shared app's own plan is tested. It covers that plan's servers, so
     * changing them after the test counts as a change.
     */
    public function forPlan(AppVersion $version, AppPlan $plan): string
    {
        return $this->hash($version, [$this->planPayload($plan)]);
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
    private function hash(AppVersion $version, array $plans): string
    {
        $payload = [
            'version' => [
                'id' => $version->id,
                'name' => $version->name,
                // update_check changes daily and says nothing about the version itself
                'settings' => Arr::except($version->settings ?? [], ['update_check']),
                'roles' => $version->roles,
                'admin_path' => $version->admin_path,
                'image_registry_id' => $version->image_registry_id,
                'helm_repo_secret_id' => $version->helm_repo_secret_id,
            ],
            'plans' => $plans,
        ];

        return Fingerprint::of($payload);
    }
}
