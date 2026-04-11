<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    protected $casts = [
        'settings' => 'array',
        'is_backup_server' => 'boolean',
    ];

    public function app_instance()
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
        $plans = $this->base_plans;
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

    public function connect(Organization $organization)
    {
        $interface = $this->interface;

        return new $inferface($organization);
    }

    public function setting(string $setting)
    {
        if ($this->settings != null) {
            if (is_array($this->settings)) {
                $settings = $this->settings;
            } else {
                $settings = json_decode($this->settings, true);
            }

            if (array_key_exists($setting, $settings)) {
                return $settings[$setting];
            }
        }

        return null;
    }

    public function updateSetting($setting, $value)
    {
        $settings = [];

        if ($this->settings != null) {
            if (is_array($this->settings)) {
                $settings = $this->settings;
            } else {
                $settings = json_decode($this->settings, true);
            }
        }

        $settings[$setting] = $value;

        $this->settings = $settings;
        $this->save();
    }
}
