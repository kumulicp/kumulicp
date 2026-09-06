<?php

namespace App;

use App\Casts\EmptyStringAsNullEncrypted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $hostname
 * @property string|null $interface
 * @property array|null $settings
 * @property bool $is_backup_server
 * @property int|null $app_instance_id
 * @property-read \App\AppInstance|null $app_instance
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\OrgServer> $org_servers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppInstance> $app_instances
 */
class Server extends Model
{
    use HasFactory;

    protected $casts = [
        'settings' => 'array',
        'is_backup_server' => 'boolean',
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AppInstance, $this>
     */
    public function app_instance(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\AppInstance', 'app_instance_id');
    }

    public function org_servers()
    {
        return $this->hasMany('App\OrgServer', 'server_id');
    }

    public function app_instances()
    {
        return $this->hasManyThrough('App\AppInstance', 'App\OrgServer');
    }

    public function tests()
    {
        $plans = $this->app_plans()->with('application')->get();
        $tests = AccountTest::limit(10);
        $collect = collect();
        foreach ($plans as $plan) {
            $app_name = $plan->application->slug;
            $json = "settings->apps->{$app_name}->plan";
            $tests->orWhere($json, $plan->id);
        }

        return $tests->get();
    }

    public function successfulBaseTests()
    {
        $plans = $this->base_plans()->get();
        $tests = AccountTest::limit(10)
            ->where('status', 'succeeded')
            ->where(function (Builder $query) use ($plans) {
                foreach ($plans as $plan) {
                    $json = 'settings->base_plan';
                    $query->orWhere($json, $plan->id);
                }
            });

        return $tests->get();
    }

    public function successfulAppTests()
    {
        $plans = $this->app_plans()?->with('application')->get();

        if ($plans) {
            $tests = AccountTest::limit(10)
                ->where('status', 'succeeded')
                ->where(function (Builder $query) use ($plans) {
                    foreach ($plans as $plan) {
                        $app_name = $plan->application->slug;
                        $json = "settings->apps->{$app_name}->plan";
                        $query->orWhere($json, $plan->id);
                    }
                });

            return $tests->get();
        }

        return false;
    }

    public function app_plans()
    {
        $column = $this->type.'_server_id';

        return $this->hasMany('App\AppPlan', $column);
    }

    public function base_plans()
    {
        $column = $this->type.'_server_id';

        return $this->hasMany('App\Plan', $column);
    }

    public function setting(string $setting)
    {
        if ($this->settings != null) {
            $settings = $this->settings;

            if (array_key_exists($setting, $settings)) {
                return $settings[$setting];
            }
        }

        return null;
    }

    public function updateSetting($setting, $value)
    {
        $settings = $this->settings ?? [];

        $settings[$setting] = $value;

        $this->settings = $settings;
        $this->save();
    }
}
