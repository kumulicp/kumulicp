<?php

use App\AppInstance;
use App\AppPlan;
use App\Support\VersionTesting\VersionFingerprint;
use App\Updates\Aspects\ReleaseAspect;
use App\UpdateState;
use App\VersionTestRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// A version's images are now part of the fingerprints that say whether a version's tests
// still count and whether an instance is up to date. The versions that already have an
// image of their own (Nextcloud's imaginary) would all look changed, though they render
// exactly as before, so every version test and instance release that was current under
// the old fingerprint is stamped with the new one. What was already stale or out of date
// is left as it was.
return new class extends Migration
{
    public function up(): void
    {
        $version_ids = DB::table('app_version_images')
            ->where('tag_from_version_name', false)
            ->distinct()
            ->pluck('app_version_id')
            ->all();

        if ($version_ids === []) {
            return;
        }

        $this->restampTestRuns($version_ids);
        $this->restampReleases($version_ids);
    }

    /**
     * @param  array<int, int>  $version_ids
     */
    private function restampTestRuns(array $version_ids): void
    {
        $fingerprints = new VersionFingerprint;

        VersionTestRun::whereIn('app_version_id', $version_ids)->with('version')->chunkById(100, function ($runs) use ($fingerprints) {
            foreach ($runs as $run) {
                $version = $run->version;
                $plan = $run->app_plan_id ? AppPlan::find($run->app_plan_id) : null;

                if (! $version || ($run->app_plan_id && ! $plan)) {
                    continue;
                }

                $before = $plan ? $fingerprints->forPlan($version, $plan, false) : $fingerprints->for($version, false);

                if ($run->fingerprint !== $before) {
                    continue;
                }

                VersionTestRun::whereKey($run->id)->update([
                    'fingerprint' => $plan ? $fingerprints->forPlan($version, $plan) : $fingerprints->for($version),
                ]);
            }
        });
    }

    /**
     * @param  array<int, int>  $version_ids
     */
    private function restampReleases(array $version_ids): void
    {
        $aspect = new ReleaseAspect;

        AppInstance::whereIn('version_id', $version_ids)->chunkById(100, function ($instances) use ($aspect) {
            foreach ($instances as $app_instance) {
                $state = UpdateState::where('app_instance_id', $app_instance->id)->where('aspect', $aspect->key())->first();

                if (! $state) {
                    continue;
                }

                try {
                    if ($state->fingerprint !== $aspect->fingerprint($app_instance, false)) {
                        continue;
                    }

                    $now = $aspect->fingerprint($app_instance);
                } catch (Throwable $exception) {
                    // Worked out again by the next scan, which flags it if it did change
                    Log::warning("Could not restamp the release of app instance {$app_instance->id}: {$exception->getMessage()}");

                    continue;
                }

                UpdateState::whereKey($state->id)->update(['fingerprint' => $now, 'current_fingerprint' => $now]);
            }
        });
    }

    public function down(): void
    {
        // What was stamped says the same as before about everything that didn't change
    }
};
