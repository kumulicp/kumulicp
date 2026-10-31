<?php

namespace App\Support\VersionTesting;

use App\AppPlan;
use App\AppVersion;
use Illuminate\Support\Arr;

/**
 * A hash of everything a version test exercises: the version's own
 * configuration and the plans a customer could install it with. A test only
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
            ->map(fn (AppPlan $plan) => [
                'id' => $plan->id,
                'settings' => $plan->settings,
                'features' => $plan->features,
                'web_server_id' => $plan->web_server_id,
                'database_server_id' => $plan->database_server_id,
                'sso_server_id' => $plan->sso_server_id,
            ])
            ->all();

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

        return hash('sha256', json_encode($this->normalize($payload)));
    }

    /**
     * Sort associative keys so the same data always encodes the same way.
     */
    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => $this->normalize($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
