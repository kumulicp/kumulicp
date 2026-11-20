<?php

namespace App;

use App\Enums\AccessType;
use App\Enums\VersionTestRunStatus;
use App\Support\VersionTesting\VersionFingerprint;
use App\Support\VersionUpdates\ChartCompatibilityChecker;
use App\Support\VersionUpdates\VersionComparator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * @property int $id
 * @property int $application_id
 * @property int|null $version_group_id
 * @property string|null $name
 * @property string|null $admin_path
 * @property array $roles
 * @property array $settings
 * @property array|null $update_check
 * @property-read Application $application
 * @property-read Collection<int, AppVersionImage> $images
 * @property-read RepoSecret|null $imageRegistry
 * @property-read RepoSecret|null $helmRepoSecret
 * @property-read AppVersionGroup|null $versionGroup
 */
class AppVersion extends Model
{
    use HasFactory;

    protected $table = 'app_versions';

    protected $casts = [
        'roles' => 'array',
        'settings' => 'array',
        'update_check' => 'array',
    ];

    protected static function booted(): void
    {
        // Every version belongs to a group, so one made without choosing joins the
        // default group of its app
        static::creating(function (AppVersion $version) {
            if ($version->version_group_id === null && $version->application_id) {
                $version->version_group_id = Application::find($version->application_id)?->defaultVersionGroup($version)->id;
            }
        });

        // Update checks only describe enabled versions: once a version is no
        // longer enabled, its last result must not keep being shown
        static::saving(function (AppVersion $version) {
            if ($version->status !== 'active') {
                $version->update_check = null;
            }
        });

        // The charts still read the image from the settings, so the primary image
        // follows them until the images are edited in their own right
        static::saved(function (AppVersion $version) {
            if ($version->wasRecentlyCreated || $version->wasChanged(['name', 'settings', 'image_registry_id'])) {
                $version->syncLegacyPrimaryImage();
            }
        });
    }

    /**
     * Every image the version runs, the primary one first.
     *
     * @return HasMany<AppVersionImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(AppVersionImage::class, 'app_version_id')
            ->orderByRaw("CASE role WHEN 'primary' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The image the app itself runs, which single-image apps have always had
     * as their only one. Null when the version has no image configured.
     */
    public function primaryImage(): ?AppVersionImage
    {
        return $this->images->first(fn (AppVersionImage $image) => $image->isPrimary());
    }

    /**
     * The images Kumulicp writes into the chart's values besides the primary one,
     * which each chart still sets itself. Images the chart ships on its own and
     * jobs' images are not written.
     *
     * @return SupportCollection<int, AppVersionImage>
     */
    public function extraImages(): SupportCollection
    {
        return $this->images
            ->filter(fn (AppVersionImage $image) => $image->role === AppVersionImage::ROLE_COMPONENT && $image->source === AppVersionImage::SOURCE_OVERRIDE)
            ->values();
    }

    /**
     * Every registry login the version's images need to be pulled, once each:
     * the version's own and those of its other images.
     *
     * @return SupportCollection<int, RepoSecret>
     */
    public function imageRegistrySecrets(): SupportCollection
    {
        $this->loadMissing('images.registrySecret');

        $secrets = collect();

        if ($this->requiresImageRegistryAuth()) {
            $secrets->push($this->imageRegistry);
        }

        foreach ($this->images as $image) {
            if ($image->requiresAuth()) {
                $secrets->push($image->registrySecret);
            }
        }

        return $secrets->unique('id')->values();
    }

    /**
     * What the images add to what the version is, beyond its name and settings,
     * which already cover the primary image that follows them (so a version with
     * only that one adds nothing, and keeps the fingerprint it had before images
     * were part of it). Only what changes what runs: not an image's label, its
     * place in the list, or how it is checked for updates.
     *
     * @param  bool  $deployed_only  Only the images the deployment depends on, as an instance's release does; a version test covers them all
     * @return array<string, array<string, mixed>> by the image's key
     */
    public function imageInputs(bool $deployed_only = false): array
    {
        $inputs = [];

        foreach ($this->images()->get() as $image) {
            if ($image->tag_from_version_name || ($deployed_only && ! $image->isDeployed())) {
                continue;
            }

            $inputs[$image->key] = [
                'role' => $image->role,
                'source' => $image->source,
                'registry' => $image->registry,
                'repository' => $image->repository,
                'tag' => $image->tag,
                'digest' => $image->digest,
                'registry_secret_id' => $image->registry_secret_id,
                'values_path' => $image->values_path,
            ];
        }

        return $inputs;
    }

    /**
     * Gives this version the images of another, apart from the primary one,
     * which follows the settings this version was given.
     */
    public function copyImagesFrom(AppVersion $source): void
    {
        $fillable = (new AppVersionImage)->getFillable();

        foreach ($source->images()->where('role', '!=', AppVersionImage::ROLE_PRIMARY)->get() as $image) {
            $this->images()->updateOrCreate(['key' => $image->key], $image->only($fillable));
        }

        $this->unsetRelation('images');
    }

    /**
     * Adds the images a profile recommends besides the primary one (the
     * 'images' of its recommendations).
     *
     * @param  array<string, mixed>  $recommendations
     */
    public function addRecommendedImages(array $recommendations): void
    {
        $fillable = (new AppVersionImage)->getFillable();

        foreach ((array) Arr::get($recommendations, 'images', []) as $position => $image) {
            $this->images()->updateOrCreate(['key' => $image['key']], Arr::only($image, $fillable) + [
                'role' => AppVersionImage::ROLE_COMPONENT,
                'source' => AppVersionImage::SOURCE_OVERRIDE,
                'position' => $position + 1,
            ]);
        }

        $this->unsetRelation('images');
    }

    /**
     * Keeps the primary image in step with image_repo_name, image_registry,
     * image_registry_id and the version's name, which is its tag. A primary
     * image that was edited as an image (no longer following the version's
     * name) is left alone.
     */
    public function syncLegacyPrimaryImage(): void
    {
        $existing = $this->images()->where('role', AppVersionImage::ROLE_PRIMARY)->first();

        if ($existing && ! $existing->tag_from_version_name) {
            return;
        }

        $repository = $this->setting('image_repo_name');

        if (! is_string($repository) || trim($repository) === '') {
            $existing?->delete();
            $this->unsetRelation('images');

            return;
        }

        $attributes = [
            'registry' => $this->setting('image_registry'),
            'repository' => $repository,
            'tag' => $this->name,
            'registry_secret_id' => $this->image_registry_id,
        ];

        if ($existing) {
            $existing->update($attributes);
        } else {
            $this->images()->create($attributes + [
                'key' => AppVersionImage::PRIMARY_KEY,
                'role' => AppVersionImage::ROLE_PRIMARY,
                'source' => AppVersionImage::SOURCE_OVERRIDE,
                'values_path' => 'image',
                'tag_from_version_name' => true,
            ]);
        }

        $this->unsetRelation('images');
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    /**
     * @return BelongsTo<AppVersionGroup, $this>
     */
    public function versionGroup(): BelongsTo
    {
        return $this->belongsTo(AppVersionGroup::class, 'version_group_id');
    }

    /**
     * The runs that test the version itself with the plans customers can pick.
     * Runs for a shared app's own plan are kept apart: see planTestRuns().
     *
     * @return HasMany<VersionTestRun, $this>
     */
    public function testRuns(): HasMany
    {
        return $this->hasMany(VersionTestRun::class, 'app_version_id')->whereNull('app_plan_id')->orderByDesc('id');
    }

    /**
     * The runs that tested this version on one specific plan.
     *
     * @return HasMany<VersionTestRun, $this>
     */
    public function planTestRuns(AppPlan $plan): HasMany
    {
        return $this->hasMany(VersionTestRun::class, 'app_version_id')->where('app_plan_id', $plan->id)->orderByDesc('id');
    }

    /**
     * Whether its tests can be bypassed right now. A run in progress is cancelled
     * to make way for the bypass, so one that can't be cancelled yet blocks it.
     */
    public function canBypassTests(?AppPlan $plan = null): bool
    {
        $runs = $plan ? $this->planTestRuns($plan) : $this->testRuns();
        $running = $runs->where('status', VersionTestRunStatus::RUNNING->value)->first();

        return ! $running || $running->canBeCancelled();
    }

    /**
     * Where this version stands before it can be enabled: running, passed,
     * bypassed, failed, stale (changed since it was approved) or untested.
     *
     * With a plan, where it stands on that plan alone. A shared app is judged
     * by its own plan's runs, never by how the version did on the others.
     */
    public function testGate(?AppPlan $plan = null): string
    {
        $runs = $plan ? $this->planTestRuns($plan) : $this->testRuns();

        if ($runs->where('status', VersionTestRunStatus::RUNNING->value)->exists()) {
            return 'running';
        }

        $run = $this->latestFinishedTestRun($plan);

        if (! $run) {
            return 'untested';
        }

        if ($run->status === VersionTestRunStatus::FAILED) {
            return 'failed';
        }

        $fingerprint = $plan ? (new VersionFingerprint)->forPlan($this, $plan) : (new VersionFingerprint)->for($this);

        if ($run->fingerprint !== $fingerprint) {
            return 'stale';
        }

        return $run->status->value;
    }

    /**
     * Whether the tests were passed or bypassed and nothing has changed since.
     */
    public function canBeEnabled(): bool
    {
        return in_array($this->testGate(), ['passed', 'bypassed'], true);
    }

    /**
     * Whether this version has passed, or had bypassed, its tests on this plan.
     */
    public function canBeActivatedOn(AppPlan $plan): bool
    {
        return in_array($this->testGate($plan), ['passed', 'bypassed'], true);
    }

    // The most recent run that reached a verdict; cancelled and running runs say nothing
    public function latestFinishedTestRun(?AppPlan $plan = null): ?VersionTestRun
    {
        return ($plan ? $this->planTestRuns($plan) : $this->testRuns())
            ->whereIn('status', [
                VersionTestRunStatus::PASSED->value,
                VersionTestRunStatus::BYPASSED->value,
                VersionTestRunStatus::FAILED->value,
            ])
            ->first();
    }

    public function imageRegistry()
    {
        return $this->belongsTo('App\RepoSecret', 'image_registry_id');
    }

    public function requiresImageRegistryAuth(): bool
    {
        return $this->image_registry_id !== null && $this->imageRegistry && $this->imageRegistry->requiresAuth();
    }

    public function helmRepoSecret()
    {
        return $this->belongsTo('App\RepoSecret', 'helm_repo_secret_id');
    }

    /**
     * The chart the version is deployed from: its group's. A version has no chart
     * of its own to change, only the chart version.
     */
    public function chart(): ?AppChart
    {
        return $this->versionGroup?->chart;
    }

    /**
     * The chart's name in its repository. Falls back to the version's own
     * settings, which is where it was kept before charts had their own.
     */
    public function chartName(): ?string
    {
        return $this->chart()?->chart_name ?: $this->setting('chart_name');
    }

    /**
     * Where the chart is published: a repository URL, an oci:// reference or the
     * name of a Rancher ClusterRepo.
     */
    public function helmRepo(): ?string
    {
        return $this->chart()?->helm_repo ?: $this->setting('helm_repo_name');
    }

    /**
     * The login the chart's repository needs. A chart decides this for all of
     * its versions, so a version only has its own when it has no chart.
     */
    public function chartRepoSecret(): ?RepoSecret
    {
        $chart = $this->chart();

        return $chart ? $chart->helmRepoSecret : $this->helmRepoSecret;
    }

    public function chartRepoSecretId(): ?int
    {
        $chart = $this->chart();

        return $chart ? $chart->helm_repo_secret_id : $this->helm_repo_secret_id;
    }

    public function requiresHelmRepoAuth(): bool
    {
        return (bool) $this->chartRepoSecret()?->requiresAuth();
    }

    /**
     * The settings that decide how the version is deployed, with the chart's
     * identity as the chart has it. For fingerprints, which must change when the
     * chart does, whichever of its versions are looked at.
     *
     * @return array<string, mixed>
     */
    public function deploymentSettings(): array
    {
        $settings = Arr::except($this->settings ?? [], ['update_check']);
        $chart = $this->chart();

        foreach (['chart_name' => $chart?->chart_name, 'helm_repo_name' => $chart?->helm_repo] as $key => $value) {
            if ($value) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    public function roles(string|array|null $type = null, $all = true): Collection
    {
        // A version with no roles set up is an empty collection, not an array: callers map() and merge() it
        $list = new Collection;
        $role_ids = $this->roles;

        if (isset($role_ids['order'])) {
            $roles = AppRole::where('access_type', '!=', AccessType::NONE->value);
            $roles->where(function (Builder $query) use ($role_ids) {

                $n = 0;
                foreach ($role_ids['order'] as $id) {

                    if ($n == 0) {
                        $query->orWhere('id', $id);
                    } else {
                        $query->orWhere('id', $id);
                    }

                    $n++;
                }
            });

            if (is_string($type)) {
                $roles->where('access_type', $type);
            } elseif (is_array($type)) {
                $roles->where(function (Builder $query) use ($type) {
                    foreach ($type as $plan_type) {
                        $query->orWhere('access_type', $plan_type);
                    }
                });
            }

            if (! $all) {
                $roles->doesntHave('implied_roles');
            }
            $list = $roles->get();
        }

        return $list;
    }

    public function group_categories()
    {
        if (Arr::has($this->roles, 'order') && count($this->roles['order']) > 0) {
            $roles = AppRole::select('category');
            $roles->where('application_id', $this->application_id);
            $roles->where('access_type', '!=', 'disabled');

            $roles->where(function (Builder $query) {
                $version_roles = $this->roles;
                $n = 0;
                foreach ($version_roles['order'] as $version_role) {
                    if ($n == 0) {
                        $query->where('id', '=', $version_role);
                    } else {
                        $query->orWhere('id', '=', $version_role);
                    }

                    $n++;
                }
            });
            $version_roles = $this->roles;
            $ids = array_map('intval', $version_roles['order']);
            $cases = implode(' ', array_map(fn ($id, $i) => "WHEN {$id} THEN {$i}", $ids, array_keys($ids)));
            $roles->orderByRaw("CASE id {$cases} ELSE 9999 END");
            $roles->groupBy('category');
            $categories = $roles->get();

            return $categories;
        }

        return collect();
    }

    public function groupsFromCategory($category)
    {
        $roles = AppRole::where('access_type', '!=', 'disabled');
        $roles->where('category', $category);

        $roles->where(function (Builder $query) {
            $version_roles = $this->roles;
            $n = 0;
            foreach ($version_roles['order'] as $version_role) {

                if ($n == 0) {
                    $query->where('id', '=', $version_role);
                } else {
                    $query->orWhere('id', '=', $version_role);
                }

                $n++;

            }
        });

        $version_roles = $this->roles;
        $ids = array_map('intval', $version_roles['order']);
        $cases = implode(' ', array_map(fn ($id, $i) => "WHEN {$id} THEN {$i}", $ids, array_keys($ids)));
        $roles->orderByRaw("CASE id {$cases} ELSE 9999 END");

        return $roles->get();
    }

    public function defaultAdminRoles()
    {
        $roles = [];
        $role_ids = $this->roles;

        if (isset($role_ids['default_admin_groups'])) {

            foreach ($role_ids['default_admin_groups'] as $id) {

                $role = AppRole::where('id', $id)
                    ->where('access_type', '!=', 'disabled')
                    ->first();

                if ($role) {

                    $roles[] = $role;

                }
            }
        }

        return $roles;
    }

    public function defaultAdminRoleSlugs()
    {
        return array_map(fn (AppRole $role) => $role->slug, $this->defaultAdminRoles());
    }

    public function defaultUserRoles()
    {
        $roles = [];
        $role_ids = $this->roles;

        if (isset($role_ids['default_user_groups'])) {

            foreach ($role_ids['default_user_groups'] as $id) {

                $role = AppRole::where('id', $id)
                    ->where('access_type', '!=', 'disabled')
                    ->first();

                if ($role) {

                    $roles[] = $role;

                }
            }
        }

        return $roles;
    }

    public function hasRole($find_role)
    {
        $roles = $this->roles;
        if (array_key_exists('roles', $roles)) {
            foreach ($roles['roles'] as $role) {
                if (array_key_exists('subroles', $role)) {
                    foreach ($role['subroles'] as $subrole) {

                        if ($subrole['slug'] == $find_role) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function setting($key)
    {
        return ($this->settings && array_key_exists($key, $this->settings)) ? $this->settings[$key] : null;
    }

    public function updateSettings(array $new_settings)
    {
        $settings = $this->settings;

        foreach ($new_settings as $key => $value) {
            $settings[$key] = $value;
        }

        $this->settings = $settings;
        $this->save();
    }

    // The newest image tag found by the update check, if still newer than this version's tag
    public function newerImageTag(): ?string
    {
        $latest = Arr::get($this->update_check ?? [], 'image.latest');

        return (new VersionComparator)->isNewer($latest, $this->name) ? $latest : null;
    }

    /**
     * The other images (not the primary one, see newerImageTag()) the update check
     * found a newer tag for, if still newer than the tag they run with.
     *
     * @return array<int, array{key: string, label: string, current: string|null, latest: string, group: string|null}>
     */
    public function newerImageTags(): array
    {
        $results = Arr::get($this->update_check ?? [], 'images', []);
        $comparator = new VersionComparator;
        $found = [];

        foreach ($this->images as $image) {
            $latest = $results[$image->key]['latest'] ?? null;

            if (! $image->isPrimary() && $comparator->isNewer($latest, $image->effectiveTag())) {
                $found[] = ['key' => $image->key, 'label' => $image->label ?: $image->key, 'current' => $image->effectiveTag(), 'latest' => $latest, 'group' => $image->group];
            }
        }

        return $found;
    }

    /**
     * Moves the images of this version, a copy of $source, that the update check of
     * $source found a newer tag for and that were picked, to that tag (and the digest
     * of that tag, for an image kept by digest). Images in the same group as a picked
     * one move with it, as they only work together.
     *
     * @param  array<int, string>  $keys  The picked images
     * @return array<int, string> The keys of the images that moved
     */
    public function adoptImageUpdates(AppVersion $source, array $keys): array
    {
        $found = collect($source->newerImageTags());
        $groups = $found->filter(fn (array $update) => $update['group'] && in_array($update['key'], $keys, true))->pluck('group')->all();
        $results = Arr::get($source->update_check ?? [], 'images', []);
        $moved = [];

        foreach ($found as $update) {
            if (! in_array($update['key'], $keys, true) && ! ($update['group'] && in_array($update['group'], $groups, true))) {
                continue;
            }

            $image = $this->images()->where('key', $update['key'])->first();

            if (! $image) {
                continue;
            }

            $image->tag = $update['latest'];

            if (! empty($results[$update['key']]['digest'])) {
                $image->digest = $results[$update['key']]['digest'];
            }

            $image->save();
            $moved[] = $update['key'];
        }

        $this->unsetRelation('images');

        return $moved;
    }

    /**
     * The other images the update check could not look up, with why.
     *
     * @return array<int, array{key: string, label: string, error: string}>
     */
    public function imageCheckErrors(): array
    {
        $results = Arr::get($this->update_check ?? [], 'images', []);
        $errors = [];

        foreach ($this->images as $image) {
            $error = $results[$image->key]['error'] ?? null;

            if (! $image->isPrimary() && $error) {
                $errors[] = ['key' => $image->key, 'label' => $image->label ?: $image->key, 'error' => $error];
            }
        }

        return $errors;
    }

    // The newest chart version that passed the compatibility check, if still newer than the one in use
    public function newerChartVersion(): ?string
    {
        $latest = Arr::get($this->update_check ?? [], 'chart.latest_compatible');

        return (new VersionComparator)->isNewer($latest, $this->setting('chart_version')) ? $latest : null;
    }

    // The newest chart release when it failed the compatibility check, with its issues
    public function incompatibleChart(): ?array
    {
        $compatibility = Arr::get($this->update_check ?? [], 'chart.compatibility');

        if (! $compatibility || $compatibility['status'] === ChartCompatibilityChecker::COMPATIBLE) {
            return null;
        }

        if (! (new VersionComparator)->isNewer($compatibility['version'] ?? null, $this->setting('chart_version'))) {
            return null;
        }

        return $compatibility;
    }

    // How the images the chart runs change in the newer chart on offer. Only said of the
    // one that passed the compatibility check, as that is the one that is offered.
    private function chartImageChanges(): array
    {
        $compatibility = Arr::get($this->update_check ?? [], 'chart.compatibility');

        if (! $compatibility || ($compatibility['status'] ?? null) !== ChartCompatibilityChecker::COMPATIBLE || ($compatibility['version'] ?? null) !== $this->newerChartVersion()) {
            return [];
        }

        return (array) ($compatibility['image_changes'] ?? []);
    }

    // What the last update check found, for display. Only enabled versions are checked.
    public function updateSummary(): ?array
    {
        $check = $this->update_check;

        if ($this->status !== 'active' || ! $check) {
            return null;
        }

        $incompatible = $this->incompatibleChart();

        return [
            'checked_at' => (new Carbon($check['checked_at']))->format('Y-m-d H:i'),
            'image' => $this->newerImageTag(),
            'image_error' => Arr::get($check, 'image.error'),
            'images' => $this->newerImageTags(),
            'images_errors' => $this->imageCheckErrors(),
            'chart_current' => $this->setting('chart_version'),
            'chart' => $this->newerChartVersion(),
            'chart_error' => Arr::get($check, 'chart.error'),
            'chart_incompatible' => $incompatible ? [
                'version' => $incompatible['version'],
                'status' => $incompatible['status'],
                'issues' => $incompatible['issues'],
            ] : null,
            'chart_warnings' => Arr::get($check, 'chart.compatibility.warnings', []),
            'chart_image_changes' => $this->chartImageChanges(),
        ];
    }
}
