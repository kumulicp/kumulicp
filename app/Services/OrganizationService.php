<?php

namespace App\Services;

use App\Application;
use App\Organization;
use App\Server;
use App\Support\Facades\AccountManager;
use App\Support\Facades\ServerInterface;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Auth;

class OrganizationService
{
    private $basic_users;

    private $standard_users;

    private $organization;

    private $apps = [];

    private $cache = [];

    public function __construct(?Organization $organization = null)
    {
        if ($organization) {
            $this->organization = $organization;
        } elseif (Auth::user() !== null) {
            if (Auth::user() instanceof Organization) {
                $this->organization = Auth::user();
            } else {
                $this->organization = Auth::user()->organization;
            }
        }
    }

    public function setOrganization(Organization $organization)
    {
        $this->organization = $organization;

        return $this;
    }

    public function account()
    {
        return $this->organization;
    }

    public function plan()
    {
        return Subscription::base();
    }

    public function apps(array $with = ['organization'], bool $active = false)
    {
        if (count($this->apps) > 0) {
            return $this->apps;
        }
        $collection = $this->organization->app_instances()->with($with);

        if ($active) {
            $collection->whereNotIn('status', ['deactivating', 'deactivated', 'deleting']);
        }

        $collection = $collection->get();

        $suborg_array = [];
        foreach ($with as $model) {
            $suborg_array[] = 'app_instances.'.$model;
        }
        foreach ($this->organization->suborganizations()->with($suborg_array)->get() as $sub) {
            $apps = $sub->app_instances;
            if (count($apps) > 0) {
                $collection = $collection->merge($apps);
            }
        }

        $this->apps = $collection;

        return $this->apps;
    }

    public function availableDomains(Application $app)
    {
        // Only show available domains if not base or parent option
        if (! in_array($app->domain_option, ['base', 'parent', 'none'])) {
            $domains = $this->organization->domains()->active()->whereNull('app_instance_id');

            // Get Parent domains
            if ($app->domain_option === 'primary') {
                $domains->whereNull('parent_domain_id');
            } elseif ($app->domain_option === 'subdomains') {
                $domains->whereNotNull('parent_domain_id');
            }

            return $domains->get();
        }

        return collect();
    }

    public function server(Server $server)
    {
        $org_server = $this->organization->servers()->where('server_id', $server->id)->first();

        if (! $org_server) {
            $org_server = OrgServerService::add($this->organization, 'email', $this->organization->plan);
        } else {
            $org_server = new OrgServerService($org_server);
        }

        return $org_server;
    }

    public function countEntity(string $entity)
    {
        if ($entity == 'base') {
            return 1;
        }

        $method = 'count'.ucfirst($entity);

        return $this->$method();
    }

    public function userTotal()
    {
        return AccountManager::users($this->organization)->collect()->count();
    }

    public function countStandard()
    {
        return AccountManager::users($this->organization)->standardUsers()->count();
    }

    public function countBasic()
    {
        return AccountManager::users($this->organization)->basicUsers()->count();
    }

    public function countAppInstances(Application $application)
    {
        return $this->organization->app_instances()->where('application_id', $application->id)->count();
    }

    public function countApplication()
    {
        return $this->organization->app_instances()->active()->count();
    }

    public function countGroups()
    {
        return AccountManager::groups()->count();
    }

    public function countStorage()
    {
        return $this->organization->additional_storage()->sum('quantity');
    }

    public function countEmail()
    {
        $count = 0;
        foreach ($this->organization->domains()->emailEnabled()->get() as $domain) {
            if ($domain->email_server && $server = ServerInterface::connect($domain->email_server)) {
                $count += count($server->emailList($domain));
            }
        }

        return $count;
    }

    public function getThis()
    {
        return $this;
    }

    public function __get($property)
    {
        return $this->organization->$property;
    }

    public function __call($method, $args = null)
    {
        return $this->organization->$method(...$args);
    }

    public function __set($property, $value)
    {
        $this->organization->$property = $value;
    }
}
