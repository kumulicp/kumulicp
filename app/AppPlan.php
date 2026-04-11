<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AppPlan extends Model
{
    use HasFactory;

    private $app_instance;

    protected $casts = [
        'settings' => 'array',
        'is_default' => 'boolean',
        'payment_enabled' => 'boolean',
        'domain_enabled' => 'boolean',
        'archive' => 'boolean',
        'features' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    public function subscribers()
    {
        return $this->hasMany('App\AppInstance', 'plan_id');
    }

    public function web_server()
    {
        return $this->belongsTo('App\Server', 'web_server_id');
    }

    public function database_server()
    {
        return $this->belongsTo('App\Server', 'database_server_id');
    }

    public function sso_server()
    {
        return $this->belongsTo('App\Server', 'sso_server_id');
    }

    public function shared_app()
    {
        return $this->belongsTo('App\AppInstance', 'global_app_id');
    }

    public function displayFeatures()
    {
        return [
            'prices' => collect([
                [
                    'name' => 'Base Price',
                    'description' => '$'.$this->setting('base.price'),
                    'price' => $this->setting('base.price'),
                ],
                [
                    'name' => 'Users',
                    'description' => '$'.$this->setting('standard.price').' per user',
                    'price' => $this->setting('standard.price'),
                ],
                [
                    'name' => Str::plural(strtolower($this->setting('basic.name'))),
                    'description' => '$'.$this->setting('basic.price').' for every '.$this->setting('basic.amount').' '.Str::plural(strtolower($this->setting('basic.name'))),
                    'price' => $this->setting('basic.price'),
                ],
                [
                    'name' => 'Additional Storage',
                    'description' => '$'.$this->setting('storage.price').' for every '.$this->setting('storage.amount').'GB',
                    'price' => $this->setting('storage.price'),
                ],
            ])->filter(function ($feature) {
                return ! empty($feature['description']) && ! empty($feature['price']);
            })->values(),
            'features' => $this->features,
        ];
    }

    public function additionalConfigs()
    {
        $configs = [];
        foreach (Arr::get($this->settings, 'additionalConfigs', []) as $key => $config) {
            $config['value'] = Arr::get($this->settings, "configurations.$key");
            $config['additional'] = true;

            $configs[$key] = $config;
        }

        return $configs;
    }

    public function dependsOn()
    {
        return 'app_instance';
    }

    public function hasValue($value)
    {
        return $this->$value ? true : false;
    }

    public function featureEnabled(string $name)
    {
        return $this->featureValue("$name.status") == 'enabled';
    }

    public function featureStatus(string $name)
    {
        return $this->featureValue("$name.status");
    }

    public function featureValue(string $name)
    {
        return Arr::get($this->settings, "features.$name", null);
    }

    public function setting($setting)
    {
        return is_array($this->settings) ? Arr::get($this->settings, $setting, null) : null;
    }

    public function updateSetting($setting, $value)
    {
        $settings = $this->settings;

        $settings[$setting] = $value;

        $this->settings = $settings;
        $this->save();
    }

    public function updateSettings(array $settings)
    {
        $current_settings = $this->settings;
        foreach ($settings as $setting => $value) {
            Arr::set($current_settings, $setting, $value);
        }

        $this->settings = $current_settings;
    }

    public function scopeActive($query)
    {
        return $query->where('archive', 0);
    }
}
