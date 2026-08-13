<?php

namespace App;

use App\Enums\AccessType;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $application_id
 * @property string $name
 * @property string $slug
 * @property \App\Enums\AccessType $access_type
 * @property array|null $required_features
 * @property bool $ignore_role
 * @property-read \App\Application|null $application
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppRole> $implied_roles
 */
class AppRole extends Model
{
    use HasFactory;

    protected $casts = [
        'access_type' => AccessType::class,
        'required_features' => 'array',
        'ignore_role' => 'boolean',
    ];

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\AppRole, $this>
     */
    public function implied_roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany('App\AppRole', 'app_implied_roles', 'primary_app_role_id', 'implied_app_role_id');
    }

    public function accessType(BasePlanService|AppInstancePlanService|null $subscription = null, ?AppInstance $app = null)
    {
        $subscription = $subscription ?? (Subscription::base()->type === 'package' ? Subscription::base() : Subscription::app_instance($app));

        $access_type = $this->access_type;
        $available_access_types = $subscription->availableAccessTypesList();
        if (($this->access_type === AccessType::BASIC && ! in_array('basic', $available_access_types))
            || ($this->access_type === AccessType::MINIMAL && ! in_array('minimal', $available_access_types) && ! in_array('basic', $available_access_types))) {
            $access_type = AccessType::STANDARD;
        } elseif ($this->access_type === AccessType::MINIMAL && ! in_array('minimal', $available_access_types) && in_array('basic', $available_access_types)) {
            $access_type = AccessType::BASIC;
        }

        return $access_type;
    }

    public function app_slug(AppInstance $app)
    {
        $app = $app->parent ?? $app;

        return $app->name.'-'.$this->slug;
    }

    public function scopeFromAppSlug(Builder $query, AppInstance $app_instance, string $slug)
    {
        $role_slug = str_replace($app_instance->name.'-', '', $slug);

        return $query->where('slug', $role_slug);
    }
}
