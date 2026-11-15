<?php

namespace App\Updates\Detection;

use App\AppInstance;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which app instances an edit to a definition can affect, and whether the edit
 * can affect them at all.
 *
 * This only narrows what is looked at. Whether an instance is out of date is
 * decided by comparing the fingerprints of its aspects (see UpdateDrift), so an
 * edit that is looked at for nothing costs a scan, while one that is wrongly
 * skipped is only found by the nightly scan. Which is why the attributes listed
 * here are the ones that are certainly cosmetic, not the ones that matter.
 */
class ImpactResolver
{
    /**
     * The attributes that never change what an instance looks like, by the
     * definition they are on. The models that are watched are the keys.
     *
     * @var array<class-string, array<int, string>>
     */
    private const COSMETIC = [
        // features is the list of the ones customers are shown; the domains are for the organization to register, not for the app
        AppPlan::class => ['name', 'description', 'display_order', 'archive', 'hidden', 'is_default', 'features', 'domain_enabled', 'domain_max', 'created_at', 'updated_at'],
        // status is whether customers can pick it, and the update checks and announcements are about the version, not the installs
        AppVersion::class => ['status', 'update_check', 'announcement_location', 'announcement_id', 'announcement_url', 'created_at', 'updated_at'],
        AppRole::class => ['label', 'description', 'category', 'created_at', 'updated_at'],
    ];

    /** @return array<int, class-string> */
    public function watched(): array
    {
        return array_keys(self::COSMETIC);
    }

    /**
     * What is left of the changed attributes once the cosmetic ones are taken out.
     *
     * @param  class-string  $subject_type
     * @param  array<int, string>  $changed
     * @return array<int, string>
     */
    public function relevant(string $subject_type, array $changed): array
    {
        return array_values(array_diff($changed, self::COSMETIC[$subject_type] ?? []));
    }

    /**
     * The instances of customers that depend on the definition. Test
     * organizations are left out: they are set up and torn down by the version tests.
     *
     * @param  class-string  $subject_type
     * @return Builder<AppInstance>|null null for a definition that isn't watched
     */
    public function targets(string $subject_type, int $subject_id, ?int $application_id): ?Builder
    {
        $query = match ($subject_type) {
            AppPlan::class => AppInstance::where('plan_id', $subject_id),
            AppVersion::class => AppInstance::where('version_id', $subject_id),
            // The roles of an app are used by every install of it
            AppRole::class => $application_id ? AppInstance::where('application_id', $application_id) : null,
            default => null,
        };

        return $query?->whereIn('organization_id', Organization::notTest()->select('organizations.id'));
    }
}
