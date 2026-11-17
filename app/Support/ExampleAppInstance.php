<?php

namespace App\Support;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Support\Facades\DB;

/**
 * A placeholder instance for an app that has none yet, so the plan editor can
 * still show what the app's charts would produce. It is a row on the app's
 * latest version (enabled or not) owned by a dedicated example organization,
 * and is never deployed. The first real instance to become active removes it
 * (see AppInstance::booted()).
 */
class ExampleAppInstance
{
    public const ORGANIZATION_SLUG = 'example-organization';

    /**
     * The newest version of the app, enabled or not.
     */
    public static function latestVersion(Application $application): ?AppVersion
    {
        return $application->versions()->latest('id')->first();
    }

    public static function for(Application $application): ?AppInstance
    {
        return AppInstance::query()
            ->where('application_id', $application->id)
            ->where('status', AppInstance::STATUS_EXAMPLE)
            ->with('organization')
            ->latest('id')
            ->first();
    }

    /**
     * Makes the app's example instance, or brings the one it has up to the
     * latest version. Null when the app has no version to build one from.
     */
    public static function generate(Application $application, AppPlan $plan): ?AppInstance
    {
        $version = self::latestVersion($application);

        if (! $version) {
            return null;
        }

        return DB::transaction(function () use ($application, $plan, $version) {
            self::clear($application->id);

            $instance = ApplicationFacade::activate(self::organization(), $application, $version, $plan)->get();

            // Nothing about it is real: no expiry, no trial, and a status the
            // checks that look after running apps don't pick up
            $instance->status = AppInstance::STATUS_EXAMPLE;
            $instance->deactivate_at = null;
            $instance->trial_ends_at = null;
            $instance->save();

            return $instance->load('organization');
        });
    }

    /**
     * Removes the app's example instance, and the example organization once
     * nothing is left in it.
     */
    public static function clear(int $application_id): void
    {
        $examples = AppInstance::query()
            ->where('application_id', $application_id)
            ->where('status', AppInstance::STATUS_EXAMPLE)
            ->get();

        if ($examples->isEmpty()) {
            return;
        }

        $organization_ids = $examples->pluck('organization_id')->unique();

        $examples->each->delete();

        Organization::query()
            ->whereIn('id', $organization_ids)
            ->where('type', Organization::TYPE_EXAMPLE)
            ->whereDoesntHave('app_instances')
            ->delete();
    }

    private static function organization(): Organization
    {
        $organization = Organization::where('slug', self::ORGANIZATION_SLUG)->first();

        // It has to read as an enabled organization: the charts build a deactivated one's app switched off
        if ($organization && $organization->status !== 'active') {
            $organization->forceFill(['status' => 'active'])->save();
        }

        return $organization ?? Organization::forceCreate([
            'slug' => self::ORGANIZATION_SLUG,
            'name' => 'Example',
            'description' => 'Owns the example app instances used to preview Helm values',
            'email' => 'example@example.invalid',
            'phone_number' => '000-000-0000',
            'contact_first_name' => 'Example',
            'contact_last_name' => 'Organization',
            'contact_email' => 'example@example.invalid',
            'contact_phone_number' => '000-000-0000',
            'street' => '1 Example St',
            'zipcode' => '00000',
            'city' => 'Example',
            'state' => 'EX',
            'country' => 'US',
            'type' => Organization::TYPE_EXAMPLE,
            'status' => 'active',
            'settings' => [],
        ]);
    }
}
