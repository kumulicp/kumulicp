<?php

namespace App\Integrations\ServerManagers\Rancher\Interfaces;

use App\AppInstance;
use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\AppInterface;
use App\Integrations\ServerManagers\Rancher\API\Application;
use App\Integrations\ServerManagers\Rancher\API\Ingress;
use App\Integrations\ServerManagers\Rancher\API\Job;
use App\Integrations\ServerManagers\Rancher\API\KubernetesNamespace;
use App\Integrations\ServerManagers\Rancher\API\Middleware;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Rancher\Services\DomainMiddlewareService;
use App\Integrations\ServerManagers\Rancher\Services\OrganizationServices;
use App\Organization;
use App\OrgServer;
use App\Support\Facades\Application as ApplicationFacade;

class RancherWebInterface implements AppInterface, OrganizationInterface
{
    use OrganizationServices;

    private $organization;

    private $namespace;

    private $middleware;

    private $application;

    private $ingress;

    private $job;

    public function __construct(
        private OrgServer $server,
        private ?AppInstance $app_instance = null,
    ) {
        $this->organization = $server->organization;

        $this->namespace = new KubernetesNamespace($this->organization, $server);
        $this->middleware = new Middleware($this->organization, $server);
        $this->application = new Application($this->organization, $server);
        $this->ingress = new Ingress($this->organization, $server);
        $this->job = new Job($this->organization, $this->server);
    }

    public function exists()
    {
        $app = new Application($this->organization, $this->server);

        return $app->isActive($this->app_instance) === 1 || $app->isActive($this->app_instance) === 2;
    }

    public function get()
    {
        $app_instance = $this->app_instance;
        if (ApplicationFacade::profile($this->app_instance->application->slug)->activationType() === 'job') {
            $app_instance = $this->app_instance->parent;
            $charts = ApplicationFacade::instance($app_instance)->charts();
        } else {
            $charts = ApplicationFacade::instance($app_instance)->charts();
        }

        return $this->application->retrieve($app_instance, $charts[0]);
    }

    public function isActive()
    {
        if (ApplicationFacade::profile($this->app_instance->application->slug)->activationType() === 'job') {
            $charts = ApplicationFacade::instance($this->app_instance->parent)->charts();
        } else {
            $charts = ApplicationFacade::instance($this->app_instance)->charts();
        }

        foreach ($charts as $chart) {
            if ($this->application->isActive($this->app_instance, $chart) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function add()
    {
        // verify the organization exists is active
        if ($this->existsOrganization()) {
            $charts = ApplicationFacade::instance($this->app_instance)->charts();

            foreach ($charts as $chart) {
                // verify app is active
                if ($this->application->isActive($this->app_instance, $chart) === 1) {
                    $this->update();
                } else {
                    $this->application->create($this->app_instance, $chart);

                    $this->app_instance->refresh();
                    // Update domain middleware
                    // $this->updateRedirectDomains();

                }
            }

            return true;
        } else {
            $this->addOrganization();
        }
    }

    public function update()
    {
        $charts = ApplicationFacade::instance($this->app_instance)->charts();

        foreach ($charts as $chart) {
            $is_active = $this->application->isActive($this->app_instance, $chart);

            if ($is_active === 1 || $is_active === 3) {
                $this->application->update($this->app_instance, $chart);

                $this->app_instance->refresh();
                // Update domain middleware
                $this->updateRedirectDomains();
            }
        }

        return true;
    }

    public function delete()
    {
        $this->updateRedirectDomains();

        $this->app_instance->refresh();

        $charts = ApplicationFacade::instance($this->app_instance)->charts();

        foreach ($charts as $chart) {
            if ($chart->delete_method === 'remove') {
                $this->application->remove($this->app_instance, $chart);
            } elseif ($chart->delete_method === 'update') {
                $this->update();
            }
        }

        return true;
    }

    public function updateRedirectDomains()
    {
        $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $this->app_instance);
        $domain_middleware->update();
    }

    public function runJob(JobChart $job_chart)
    {
        return $this->job->create($job_chart);
    }

    public function jobStatus(string $job_id)
    {
        return $this->job->status($job_id);
    }
}
