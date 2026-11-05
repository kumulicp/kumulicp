<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Helm chart repository whose charts are imported as catalog applications
 * (see App\Support\Catalog\ChartCatalogSync).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $url
 * @property string $type
 * @property int|null $helm_repo_secret_id
 * @property bool $enabled
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 */
class ChartSource extends Model
{
    protected $table = 'chart_sources';

    protected $fillable = ['name', 'slug', 'url', 'type', 'helm_repo_secret_id', 'enabled', 'last_synced_at', 'last_sync_error'];

    protected $casts = [
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function helmRepoSecret(): BelongsTo
    {
        return $this->belongsTo(RepoSecret::class, 'helm_repo_secret_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'chart_source_id');
    }
}
