<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A track of versions of one chart ("Nextcloud 32", "Nextcloud 33"). Each group
 * has an enabled version of its own, and plans that pick a group are only
 * upgraded within it.
 *
 * @property int $id
 * @property int $app_chart_id
 * @property string $name
 * @property string $slug
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppChart $chart
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AppVersion> $versions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AppPlan> $plans
 */
class AppVersionGroup extends Model
{
    use HasFactory;

    protected $table = 'app_version_groups';

    protected $fillable = ['app_chart_id', 'name', 'slug', 'is_default'];

    protected $attributes = [
        'is_default' => false,
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * @return BelongsTo<AppChart, $this>
     */
    public function chart(): BelongsTo
    {
        return $this->belongsTo(AppChart::class, 'app_chart_id');
    }

    /**
     * @return HasMany<AppVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class, 'version_group_id');
    }

    /**
     * @return HasMany<AppPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(AppPlan::class, 'version_group_id');
    }

    /**
     * Whether this is the group of the app that plans and versions use when they pick none.
     */
    public function isAppDefault(): bool
    {
        return $this->is_default && $this->chart->is_default;
    }

    /**
     * The plans whose versions this group decides: those that picked it, and for
     * the default group of an app also those that picked none.
     *
     * @return SupportCollection<int, int>
     */
    public function planIds(): SupportCollection
    {
        return AppPlan::where('application_id', $this->chart->application_id)
            ->where(function ($query) {
                $query->where('version_group_id', $this->id);

                if ($this->isAppDefault()) {
                    $query->orWhereNull('version_group_id');
                }
            })
            ->pluck('id');
    }

    /**
     * @return HasOne<AppVersion, $this>
     */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(AppVersion::class, 'version_group_id')->where('status', 'active');
    }
}
