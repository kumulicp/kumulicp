<?php

namespace App;

use App\Support\Facades\AccountManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Laravel\Cashier\Billable;

use function Illuminate\Events\queueable;

class Organization extends Model
{
    use Billable, HasFactory;

    protected $table = 'organizations';

    protected $casts = [
        'secretpw' => 'encrypted',
        'api_token' => 'hashed',
        'settings' => 'array',
        'deactivate_at' => 'date',
    ];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        static::updated(queueable(function ($organization) {
            if ($organization->stripe_id) {
                $organization->syncStripeCustomerDetails();
            }
        }));
    }

    public function domains()
    {
        return $this->hasMany('App\OrgDomain', 'organization_id');
    }

    public function subdomains()
    {
        return $this->hasMany('App\OrgSubdomain', 'organization_id');
    }

    public function backups()
    {
        return $this->hasMany('App\OrgBackup', 'organization_id');
    }

    public function primary_domain()
    {
        return $this->belongsTo('\App\OrgDomain', 'primary_domain_id');
    }

    public function base_domain()
    {
        return $this->belongsTo('App\OrgDomain', 'base_domain_id');
    }

    public function email_forwarders()
    {
        return $this->hasMany('App\EmailForwarder', 'organization_id');
    }

    public function plan()
    {
        return $this->belongsTo('App\Plan', 'plan_id');
    }

    public function servers()
    {
        return $this->hasMany('App\OrgServer', 'organization_id');
    }

    public function users()
    {
        return $this->hasMany('App\User', 'organization_id');
    }

    public function logs()
    {
        return $this->hasMany('App\Log', 'organization_id');
    }

    public function applications()
    {
        return $this->belongsToMany('App\Application', 'app_instances', 'organization_id', 'application_id');
    }

    public function app_instances()
    {
        return $this->hasMany('App\AppInstance', 'organization_id');
    }

    public function additional_storage()
    {
        return $this->hasMany('App\AdditionalStorage', 'organization_id');
    }

    public function tasks()
    {
        return $this->hasMany('App\Task', 'organization_id');
    }

    public function primary_contact()
    {
        return $this->belongsTo('App\User', 'primary_contact_id');
    }

    public function new_user_codes()
    {
        return $this->hasMany('App\NewUserCode', 'organization_id');
    }

    public function account_test()
    {
        return $this->belongsTo('App\AccountTest', 'account_test_id');
    }

    public function parent_organization()
    {
        return $this->belongsTo('App\Organization', 'parent_organization_id');
    }

    public function suborganizations()
    {
        return $this->hasMany('App\Organization', 'parent_organization_id');
    }

    public function suborg_users()
    {
        return $this->hasMany('App\SuborgUser', 'organization_id');
    }

    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    public static function account()
    {
        if (Auth::user() !== null) {
            if (Auth::user() instanceof Organization) {
                return Auth::user();
            } else {
                return Auth::user()->organization;
            }
        }

        return null;
    }

    public function main_domains()
    {
        return $this->domains()->active()->whereNull('parent_domain_id')->orWhere('parent_domain_id', 0)->get();
    }

    public function admins()
    {
        return AccountManager::account($this)->users()->orgAdmins();
    }

    public function active_apps()
    {
        return AppInstance::where('organization_id', $this->id)->where('status', 'active')
            ->with('application', 'version')
            ->get();
    }

    public function allOrganizationIds()
    {
        $ids = [$this->id];

        foreach ($this->suborganizations as $suborg) {
            $ids[] = $suborg->id;
        }

        return $ids;
    }

    public function allAppsIncludingChildren()
    {
        $suborg_apps = [];
        foreach ($this->suborganizations()->with('app_instances')->get() as $suborg) {
            foreach ($suborg->app_instances as $app) {
                $suborg_apps[] = $app;
            }
        }

        return $this->app_instances->merge($suborg_apps);
    }

    public function activeAppsIncludingChildren()
    {
        $suborg_apps = [];
        foreach ($this->suborganizations()->with('app_instances')->get() as $suborg) {
            foreach ($suborg->app_instances()->active()->get() as $app) {
                $suborg_apps[] = $app;
            }
        }

        return $this->app_instances->merge($suborg_apps);
    }

    public function appByName($app)
    {
        $application = Application::where('slug', $app)->first();

        return $application ? AppInstance::where('organization_id', $this->id)
            ->where('application_id', $application->id)
            ->first() : null;
    }

    public function countEntity(string $entity)
    {
        $method = 'count'.ucfirst($entity);

        return $this->$method();
    }

    public function countApplication()
    {
        return $this->app_instances()->active()->count();
    }

    public function countStorage()
    {
        return $this->additional_storage->count();
    }

    public function countEmail()
    {
        return 0;
    }

    public function activeAppFamilies()
    {
        $select_apps = [];

        foreach ($this->activeAppsIncludingChildren() as $app) {
            if (! $app->parent_id) {
                $select_apps[] = $app;
            }
        }

        return $select_apps;
    }

    public function stripeName()
    {
        return $this->name;
    }

    public function stripeEmail()
    {
        return $this->contact_email;
    }

    public function stripePhone()
    {
        return $this->contact_phone_number;
    }

    public function stripeAddress()
    {
        $address = [
            'line1' => $this->street,
            'postal_code' => $this->zipcode,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
        ];

        return $address;
    }

    public function notifyAdmins($notification)
    {
        $admins = $this->admins();

        foreach ($admins as $admin) {
            $admin->notify($notification);
        }
    }

    public function setting($setting)
    {
        return Arr::get($this->settings, $setting);
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

        Arr::set($settings, $setting, $value);

        $this->settings = $settings;
    }

    public function reactivate()
    {
        $this->status = 'active';
        $this->save();

        foreach ($this->domains as $domain) {
            foreach ($domain->servers as $server) {
                $server->updateOrganization();
            }
        }
    }

    public function deactivate()
    {
        $this->status = 'deactivated';
        $this->save();

        foreach ($this->domains as $domain) {
            foreach ($domain->servers as $server) {
                $server->updateOrganization();
            }
        }
    }

    public function scopeNotDeactivated(Builder $query)
    {
        return $query->where('status', '!=', 'deactivated');
    }
}
