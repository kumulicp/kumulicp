<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * @property int $id
 * @property string $name
 * @property int|null $email_server_id
 * @property array|null $app_plans
 * @property array|null $settings
 * @property array|null $features
 * @property bool $is_default
 * @property bool $payment_enabled
 * @property bool $domain_enabled
 * @property bool $email_enabled
 * @property bool $archive
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Organization> $subscribers
 * @property-read \App\Server|null $email_server
 */
class Plan extends Model
{
    use HasFactory;

    protected $table = 'plans';

    private const BUILT_IN_SERVER_TYPES = ['email'];

    private $organization;

    protected $fillable = [
        'name', 'app_plans', 'settings', 'is_default', 'payment_enabled', 'domain_enabled', 'email_enabled', 'archive', 'features',
    ];

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

    public function serverFor(string $type): ?Server
    {
        if (in_array($type, self::BUILT_IN_SERVER_TYPES)) {
            return $this->{$type.'_server'};
        }

        return ServerAssignment::where('assignable_type', static::class)
            ->where('assignable_id', $this->id)
            ->where('server_type', $type)
            ->first()?->server;
    }

    public function assignServer(string $type, Server $server): void
    {
        if (in_array($type, self::BUILT_IN_SERVER_TYPES)) {
            $this->{$type.'_server_id'} = $server->id;
            $this->save();

            return;
        }

        ServerAssignment::updateOrCreate(
            ['assignable_type' => static::class, 'assignable_id' => $this->id, 'server_type' => $type],
            ['server_id' => $server->id]
        );
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
