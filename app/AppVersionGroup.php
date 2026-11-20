<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
     * @return HasOne<AppVersion, $this>
     */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(AppVersion::class, 'version_group_id')->where('status', 'active');
    }
}
