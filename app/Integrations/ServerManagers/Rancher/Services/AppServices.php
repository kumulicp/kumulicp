<?php

namespace App\Integrations\ServerManagers\Rancher\Services;

use App\AppInstance;
use App\Integrations\ServerManagers\Rancher\API\Application;
use App\Integrations\ServerManagers\Rancher\API\Middleware;
use App\Organization;

trait AppServices
{
    public function exists(AppInstance $app_instance)
    {
        $app = new Application($this->organization, $this->server);

        return $app->isActive($app_instance) === 1 || $app->isActive($app_instance) === 2;
    }

    public function get(AppInstance $app_instance) {}

    public function isActive(AppInstance $app_instance)
    {
        if ($parent = $app_instance->parent) {
            $app_instance = $parent;
        }

        $app = new Application($this->organization, $this->server);

        return $app->isActive($app_instance) === 1;
    }

    public function add(AppInstance $app_instance)
    {
        $organization = $this->organization;

        if ($app_instance->application->parent_app_id > 0) {
            return $this->update();
        }

        // verify the organization exists is active
        if ($this->existsOrganization()) {
            // verify nextcloud is active
            if ($this->application->isActive($app_instance) !== 1) {
                $this->application->create($app_instance);

                $app_instance->refresh();
                // Update domain middleware
                $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $app_instance);
                $domain_middleware->update();

                return true;
            }
        } else {
            $this->addOrganization();
        }
    }

    public function update()
    {
        if ($app_instance->application->parent_app_id > 0 && $app = $app_instance->application->parent_app) {
            $app_instance = $app->isAppInstance($this->organization);
        }

        $is_active = $this->application->isActive($app_instance);

        if ($is_active === 1 || $is_active === 3) {
            $this->application->update($app_instance);

            $app_instance->refresh();
            // Update domain middleware
            $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $app_instance);
            $domain_middleware->update();

            return true;
        }
    }

    public function delete(AppInstance $app_instance)
    {
        $is_active = $this->application->isActive($app_instance);
        if ($is_active === 1 || $is_active === 3) {
            $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $app_instance);
            $domain_middleware->update();

            $app_instance->refresh();
            $this->application->remove($app_instance);

            return true;
        }
    }
}
