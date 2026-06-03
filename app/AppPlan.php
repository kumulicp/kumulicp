<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $application_id
 * @property string $name
 * @property array|null $settings
 * @property bool $is_default
 * @property bool $payment_enabled
 * @property bool $domain_enabled
 * @property bool $archive
 * @property array|null $features
 * @property int|null $web_server_id
 * @property int|null $database_server_id
 * @property int|null $sso_server_id
 * @property int|null $global_app_id
 * @property-read \App\Application|null $application
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppInstance> $subscribers
 * @property-read \App\Server|null $web_server
 * @property-read \App\Server|null $database_server
 * @property-read \App\Server|null $sso_server
 * @property-read \App\AppInstance|null $shared_app
 */
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
