<?php

namespace App;

use App\Enums\ApplicationSource;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * One deployable source of an application: a Helm chart and where it is
 * published. An app can have several (the registered Nextcloud chart and
 * HelmForge's); its versions are kept in the version groups of the chart.
 *
 * @property int $id
 * @property int $application_id
 * @property string $name
 * @property string $slug
 * @property string|null $chart_name
 * @property string|null $helm_repo
 * @property int|null $helm_repo_secret_id
 * @property int|null $chart_source_id
 * @property ApplicationSource $source
 * @property array|null $catalog
 * @property bool $is_default
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Application $application
 * @property-read RepoSecret|null $helmRepoSecret
 * @property-read ChartSource|null $chartSource
 * @property-read SupportCollection<int, AppVersionGroup> $versionGroups
 */
class AppChart extends Model
{
    use HasFactory;

    protected $table = 'app_charts';

    protected $fillable = ['application_id', 'name', 'slug', 'chart_name', 'helm_repo', 'helm_repo_secret_id', 'chart_source_id', 'source', 'catalog', 'is_default', 'enabled'];

    protected $attributes = [
        'source' => 'generic',
        'is_default' => false,
        'enabled' => true,
    ];

    protected $casts = [
        'source' => ApplicationSource::class,
        'catalog' => 'array',
        'is_default' => 'boolean',
        'enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Which profile an app's installs use is worked out from its charts
        static::saved(fn (AppChart $chart) => ApplicationFacade::forgetCharts($chart->application_id));
        static::deleted(fn (AppChart $chart) => ApplicationFacade::forgetCharts($chart->application_id));
    }

    /**
     * The chart the given version is deployed from, for an app that has none yet.
     */
    public static function createDefaultFor(Application $application, ?AppVersion $version = null): self
    {
        return self::create([
            'application_id' => $application->id,
            'name' => $application->chartSource->name ?? 'Default',
            'slug' => 'default',
            'chart_name' => self::text($version?->setting('chart_name')),
            'helm_repo' => self::text($version?->setting('helm_repo_name')),
            'helm_repo_secret_id' => $version?->helm_repo_secret_id,
            'chart_source_id' => $application->chart_source_id,
            'source' => $application->source,
            'is_default' => true,
        ]);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /**
     * @return BelongsTo<RepoSecret, $this>
     */
    public function helmRepoSecret(): BelongsTo
    {
        return $this->belongsTo(RepoSecret::class, 'helm_repo_secret_id');
    }

    /**
     * @return BelongsTo<ChartSource, $this>
     */
    public function chartSource(): BelongsTo
    {
        return $this->belongsTo(ChartSource::class, 'chart_source_id');
    }

    /**
     * @return HasMany<AppVersionGroup, $this>
     */
    public function versionGroups(): HasMany
    {
        return $this->hasMany(AppVersionGroup::class, 'app_chart_id');
    }

    /**
     * The group versions go in when none is chosen, made when the chart has none.
     */
    public function defaultVersionGroup(): AppVersionGroup
    {
        return $this->versionGroups()->where('is_default', true)->first()
            ?? $this->versionGroups()->create(['name' => 'Default', 'slug' => 'default', 'is_default' => true]);
    }
}
