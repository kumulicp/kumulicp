<?php

namespace App;

use App\Enums\VersionTestRunStatus;
use App\Support\VersionTesting\VersionFingerprint;
use App\Support\VersionUpdates\ChartCompatibilityChecker;
use App\Support\VersionUpdates\VersionComparator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property string|null $name
 * @property string|null $admin_path
 * @property array $roles
 * @property array $settings
 * @property array|null $update_check
 * @property-read Application $application
 * @property-read RepoSecret|null $imageRegistry
 * @property-read RepoSecret|null $helmRepoSecret
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
        // Update checks only describe enabled versions: once a version is no
        // longer enabled, its last result must not keep being shown
        static::saving(function (AppVersion $version) {
            if ($version->status !== 'active') {
                $version->update_check = null;
            }
        });
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    /**
     * @return HasMany<VersionTestRun, $this>
     */
    public function testRuns(): HasMany
    {
        return $this->hasMany(VersionTestRun::class, 'app_version_id')->orderByDesc('id');
    }

    /**
     * Where this version stands before it can be enabled: running, passed,
     * bypassed, failed, stale (changed since it was approved) or untested.
     */
    public function testGate(): string
    {
        if ($this->testRuns()->where('status', VersionTestRunStatus::RUNNING->value)->exists()) {
            return 'running';
        }

        $run = $this->latestFinishedTestRun();

        if (! $run) {
            return 'untested';
        }

        if ($run->status === VersionTestRunStatus::FAILED) {
            return 'failed';
        }

        if ($run->fingerprint !== (new VersionFingerprint)->for($this)) {
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

    // The most recent run that reached a verdict; cancelled and running runs say nothing
    public function latestFinishedTestRun(): ?VersionTestRun
    {
        return $this->testRuns()
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

    public function requiresHelmRepoAuth(): bool
    {
        return $this->helm_repo_secret_id !== null && $this->helmRepoSecret && $this->helmRepoSecret->requiresAuth();
    }

    public function roles(string|array|null $type = null, $all = true)
    {
        $list = [];
        $role_ids = $this->roles;

        if (isset($role_ids['order'])) {
            $roles = AppRole::where('access_type', '!=', 'disabled');
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
        $roles = [];
        $role_ids = $this->roles;

        if (isset($role_ids['default_admin_roles'])) {

            foreach ($role_ids['default_admin_roles'] as $id) {

                $role = AppRole::where('id', $id)
                    ->where('access_type', '!=', 'disabled')
                    ->first();

                if ($role) {

                    $roles[] = $role->slug;

                }
            }
        }

        return $roles;
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
            'chart_current' => $this->setting('chart_version'),
            'chart' => $this->newerChartVersion(),
            'chart_error' => Arr::get($check, 'chart.error'),
            'chart_incompatible' => $incompatible ? [
                'version' => $incompatible['version'],
                'status' => $incompatible['status'],
                'issues' => $incompatible['issues'],
            ] : null,
            'chart_warnings' => Arr::get($check, 'chart.compatibility.warnings', []),
        ];
    }
}
