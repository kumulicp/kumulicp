<?php

namespace App\Integrations\SSO\Authentik\Interfaces;

use App\AppInstance;
use App\Integrations\SSO\Authentik\API\Applications;
use App\Integrations\SSO\Authentik\API\Groups;
use App\Integrations\SSO\Authentik\API\PolicyBindings;
use App\Integrations\SSO\Authentik\API\Providers;
use App\Integrations\SSO\Authentik\API\Sources;
use App\OrgServer;
use Illuminate\Support\Arr;

class AuthentikSSOInterface
{
    private $organization;

    private $providers;

    private $applications;

    private $groups;

    public function __construct(
        private OrgServer $server,
        private ?AppInstance $app_instance = null,
    ) {
        $this->organization = $server->organization;

        $this->providers = new Providers($this->organization, $server);
        $this->applications = new Applications($this->organization, $server);
        $this->groups = new Groups($this->organization, $server);
        $this->policy_bindings = new PolicyBindings($this->organization, $server);
        $this->sources = new Sources($this->organization, $server);
    }

    public function exists()
    {
        return $this->applications->exists($this->app_instance);
    }

    public function get()
    {
        $this->groups->list($this->app_instance);
    }

    public function isActive() {}

    public function add()
    {
        $this->sources->LDAPSync($this->app_instance);

        if (! $this->providers->exists($this->app_instance)) {
            $provider = $this->providers->create($this->app_instance);
        } else {
            $provider = $this->providers->find($this->app_instance);
        }

        if (is_array($provider)) {
            if (! $this->applications->exists($this->app_instance)) {
                $application = $this->applications->create($this->app_instance, (int) $provider['pk']);
                $this->app_instance->updateSetting('sso', $application);

                $app_id = Arr::get($application, 'pk', null);

                foreach ($this->groups->list($this->app_instance) as $group) {
                    $group_id = Arr::get($group, 'pk', null);

                    if ($app_id && $group_id) {
                        $this->policy_bindings->create(target: $app_id, group: $group_id);
                    }
                }
            } else {
                $application = $this->update();
            }
        }

        return $application;
    }

    public function update()
    {
        $this->sources->LDAPSync($this->app_instance);
        if ($this->exists()) {
            $application = $this->applications->update($this->app_instance);
            $this->app_instance->updateSetting('sso', $application);

            $app_id = Arr::get($application, 'pk', null);

            foreach ($this->policy_bindings->list($this->app_instance) as $policy_binding) {
                $this->policy_bindings->remove(target: $app_id, id: $policy_binding['pk']);
            }

            foreach ($this->groups->list($this->app_instance) as $group) {
                $group_id = Arr::get($group, 'pk', null);

                if ($app_id && $group_id) {
                    $this->policy_bindings->create(target: $app_id, group: $group_id);
                }
            }
        } else {
            $application = $this->add();
        }

        return $application;
    }

    public function delete()
    {
        $provider = $this->providers->remove($this->app_instance);
        $application = $this->applications->remove($this->app_instance);

        return true;
    }

    public function existsOrganization()
    {
        return true;
    }
}
