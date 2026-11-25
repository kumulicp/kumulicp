<?php

namespace App;

use App\Enums\ApplicationSource;
use App\Support\VersionUpdates\VersionComparator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Arr;
use OffloadProject\Toggle\Facades\Toggle;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ApplicationSource $source
 * @property int|null $chart_source_id
 * @property array|null $catalog
 * @property-read Collection<int, Organization> $organizations
 * @property-read Collection<int, AppInstance> $instances
 * @property-read int|null $total_instances
 * @property-read int|null $active_instances
 * @property-read Collection<int, AppVersion> $versions
 * @property-read Collection<int, AppPlan> $plans
 * @property-read Collection<int, AppChart> $charts
 * @property-read Collection<int, AppRole> $roles
 * @property-read Collection<int, AppScreenshot> $screenshots
 * @property-read Collection<int, Tag> $tags
 */
class Application extends Model
{
    use HasFactory;

    protected $table = 'applications';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'generic',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'source' => ApplicationSource::class,
        'catalog' => 'array',
    ];

    /**
     * Determine whether the given domain type(s) are allowed for this app.
     *
     * @param  string|array  $types
     */
    public function hasDomainOption($types): bool
    {
        $allowed = $this->domain_option ?? [];

        foreach ((array) $types as $type) {
            if (in_array($type, $allowed)) {
                return true;
            }
        }

        return false;
    }

    // Imported from the HelmForge chart repository rather than backed by a hand-written profile
    public function isHelmForge(): bool
    {
        return $this->source === ApplicationSource::HelmForge;
    }

    /**
     * Applications that may be offered right now: HelmForge ones only while
     * the HelmForge feature is on.
     *
     * @param  Builder<Application>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        if (! Toggle::active('helmforge')) {
            $query->where('source', '!=', ApplicationSource::HelmForge);
        }
    }

    public function chartSource()
    {
        return $this->belongsTo(ChartSource::class, 'chart_source_id');
    }

    /**
     * @return HasMany<AppChart, $this>
     */
    public function charts(): HasMany
    {
        return $this->hasMany(AppChart::class, 'application_id');
    }

    /**
     * The group a new version goes in when none is chosen: the default group of
     * the default chart. An app that has no chart yet gets one, taken from the
     * version being added.
     */
    public function defaultVersionGroup(?AppVersion $version = null): AppVersionGroup
    {
        $chart = $this->charts()->where('is_default', true)->first() ?? AppChart::createDefaultFor($this, $version);

        return $chart->defaultVersionGroup();
    }

    /**
     * The default group, when the app has one yet. Unlike defaultVersionGroup()
     * it makes nothing.
     */
    public function findDefaultVersionGroup(): ?AppVersionGroup
    {
        return $this->versionGroups()->where('app_version_groups.is_default', true)->where('app_charts.is_default', true)->first();
    }

    /**
     * Every group of every chart of the app.
     *
     * @return HasManyThrough<AppVersionGroup, AppChart, $this>
     */
    public function versionGroups(): HasManyThrough
    {
        return $this->hasManyThrough(AppVersionGroup::class, AppChart::class, 'application_id', 'app_chart_id');
    }

    /**
     * The groups a new plan can pick: those of all its charts.
     *
     * @return \Illuminate\Support\Collection<int, AppVersionGroup>
     */
    public function selectableVersionGroups()
    {
        return $this->versionGroups()
            ->with('chart')
            ->orderBy('app_charts.id')
            ->orderBy('app_version_groups.id')
            ->get();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'application_tag');
    }

    /**
     * @param  Builder<Application>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $like = '%'.addcslashes(trim($term), '\%_').'%';

        $query->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('slug', 'like', $like));
    }

    /**
     * Applications carrying any of the given tag slugs.
     *
     * @param  Builder<Application>  $query
     * @param  array<int, string>  $slugs
     */
    public function scopeWithAnyTag(Builder $query, array $slugs): void
    {
        if ($slugs) {
            $query->whereHas('tags', fn (Builder $query) => $query->whereIn('tags.slug', $slugs));
        }
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'app_instances', 'application_id', 'organization_id');
    }

    /**
     * @return HasMany<AppInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany('App\AppInstance', 'application_id');
    }

    /**
     * The installs of real customers running an older version than the given
     * one, among those on plans that use the version's group. Test orgs are left
     * out: they are deleted, not upgraded.
     *
     * @return Collection<int, AppInstance>
     */
    public function instancesBehind(AppVersion $version): Collection
    {
        $comparator = new VersionComparator;
        $group = $version->versionGroup;

        return $this->instances()
            ->whereIn('organization_id', Organization::notTest()->select('organizations.id'))
            ->when($group, fn (Builder $query) => $query->where(function (Builder $query) use ($group) {
                $query->whereIn('plan_id', $group->planIds());

                if ($group->isAppDefault()) {
                    $query->orWhereNull('plan_id');
                }
            }))
            ->with('version')
            ->get()
            ->filter(fn (AppInstance $instance) => $instance->version && $comparator->compare($version->name, $instance->version->name) > 0)
            ->values();
    }

    /**
     * The enabled version of the default group: what a plan that picked no group
     * is installed and upgraded with. See AppPlan::activeVersion() for a plan's own.
     */
    public function active_version()
    {
        $group = $this->findDefaultVersionGroup();

        return $group ? $group->activeVersion : $this->versions()->where('status', 'active')->first();
    }

    /**
     * @return HasMany<AppVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany('App\AppVersion', 'application_id');
    }

    /**
     * @return HasMany<AppPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany('App\AppPlan', 'application_id');
    }

    public function default_plan()
    {
        return $this->plans()->where('is_default', 1)->first();
    }

    /**
     * @return HasMany<AppRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany('App\AppRole', 'application_id');
    }

    public function screenshots()
    {
        return $this->hasMany('App\AppScreenshot', 'application_id')->orderBy('display_order');
    }

    /**
     * The domain and parent app settings a new plan of the app starts with: those of its
     * first plan, so that adding a plan doesn't leave an app that can't be activated.
     *
     * @return array<string, mixed>
     */
    public function inheritedPlanSettings(): array
    {
        $plan = $this->plans()->orderBy('id')->first();

        return Arr::only($plan->settings ?? [], ['can_update_domain', 'domain_option', 'parent_app_id']);
    }

    /**
     * Whether any plan of the app makes it an addon to another app.
     */
    public function hasParentApp(): bool
    {
        return $this->plans->contains(fn (AppPlan $plan) => $plan->parentApplication() !== null);
    }

    public function is_installed(Organization $organization)
    {
        return $organization->app_instances()->where('application_id', $this->id)->count() > 0;
    }

    public function isAppInstance($organization = null)
    {
        $organization = $organization ? $organization : Organization::account();

        return $organization
            ? AppInstance::where('application_id', $this->id)->where('organization_id', $organization->id)->first()
            : null;
    }
}
