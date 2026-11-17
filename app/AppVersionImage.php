<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One container image an app version runs. A version is a bundle: its chart
 * version plus every image listed here.
 *
 * @property int $id
 * @property int $app_version_id
 * @property string $key
 * @property string|null $label
 * @property string $role primary, component or job
 * @property string $source override or chart
 * @property string|null $registry
 * @property string $repository
 * @property string|null $tag
 * @property string|null $digest
 * @property int|null $registry_secret_id
 * @property string $values_path
 * @property bool $tag_from_version_name
 * @property string $update_policy
 * @property string|null $tag_pattern
 * @property string|null $group
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AppVersion $version
 * @property-read RepoSecret|null $registrySecret
 */
class AppVersionImage extends Model
{
    use HasFactory;

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_COMPONENT = 'component';

    public const ROLE_JOB = 'job';

    // Kumulicp writes the image into the chart's values
    public const SOURCE_OVERRIDE = 'override';

    // The chart's own default, recorded so it can be checked and diffed
    public const SOURCE_CHART = 'chart';

    public const PRIMARY_KEY = 'primary';

    protected $table = 'app_version_images';

    protected $fillable = [
        'key',
        'label',
        'role',
        'source',
        'registry',
        'repository',
        'tag',
        'digest',
        'registry_secret_id',
        'values_path',
        'tag_from_version_name',
        'update_policy',
        'tag_pattern',
        'group',
        'position',
    ];

    protected $casts = [
        'tag_from_version_name' => 'boolean',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'app_version_id');
    }

    /**
     * @return BelongsTo<RepoSecret, $this>
     */
    public function registrySecret(): BelongsTo
    {
        return $this->belongsTo(RepoSecret::class, 'registry_secret_id');
    }

    public function isPrimary(): bool
    {
        return $this->role === self::ROLE_PRIMARY;
    }

    /**
     * Whether what Kumulicp deploys depends on this image: the primary one and the
     * components it writes into the chart's values. The chart's own images and the
     * jobs' are only recorded.
     */
    public function isDeployed(): bool
    {
        return $this->isPrimary() || ($this->role === self::ROLE_COMPONENT && $this->source === self::SOURCE_OVERRIDE);
    }

    /**
     * The tag the image runs with. A single-image app keeps using the version's
     * name as its tag, so the two can't drift apart.
     */
    public function effectiveTag(): ?string
    {
        if ($this->tag_from_version_name) {
            return $this->version->name;
        }

        return $this->tag;
    }

    public function requiresAuth(): bool
    {
        return $this->registry_secret_id !== null && $this->registrySecret && $this->registrySecret->requiresAuth();
    }

    /**
     * The registry to pull from: the secret's, then the image's own.
     */
    public function effectiveRegistry(): ?string
    {
        return $this->registrySecret?->registry ?: ($this->registry ?: null);
    }

    /**
     * The full reference, e.g. docker.io/library/nextcloud:33.0.1 or
     * ghcr.io/org/app:1.2@sha256:...
     */
    public function reference(): string
    {
        $registry = $this->effectiveRegistry();
        $reference = $registry ? $registry.'/'.$this->repository : $this->repository;

        if ($tag = $this->effectiveTag()) {
            $reference .= ':'.$tag;
        }

        if ($this->digest) {
            $reference .= '@'.$this->digest;
        }

        return $reference;
    }
}
