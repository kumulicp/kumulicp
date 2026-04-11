<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Plan extends Model
{
    use HasFactory;

    protected $table = 'plans';

    private $organization;

    protected $casts = [
        'app_plans' => 'array',
        'settings' => 'array',
        'is_default' => 'boolean',
        'payment_enabled' => 'boolean',
        'domain_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'archive' => 'boolean',
        'features' => 'array',
    ];

    public function subscribers()
    {
        return $this->hasMany('App\Organization', 'plan_id');
    }

    public function email_server()
    {
        return $this->belongsTo('App\Server', 'email_server_id');
    }

    public function displayFeatures()
    {
        return is_array($this->features) ? $this->features : [];
    }

    public function hasValue($value)
    {
        return $this->$value ? true : false;
    }

    public function appPlansList(Application $app)
    {
        $plan = "{$app->slug}.plans";
        if (Arr::has($this->app_plans, $plan)) {
            return Arr::get($this->app_plans, $plan, []);
        }

        // Will return null if app not found
        return Arr::get($this->app_plans, $app->slug, []);
    }

    public function appMax(Application $app)
    {
        $max = "{$app->slug}.max";

        return Arr::get($this->app_plans, $max);
    }

    public function setting($setting)
    {
        return is_array($this->settings) ? Arr::get($this->settings, $setting, null) : null;
    }

    public function updateSettings(array $settings)
    {
        $current_settings = $this->settings;
        foreach ($settings as $setting => $value) {
            Arr::set($current_settings, $setting, $value);
        }

        $this->settings = $current_settings;
    }
}
