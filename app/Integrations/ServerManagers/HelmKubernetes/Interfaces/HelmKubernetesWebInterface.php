<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Interfaces;

use App\AppInstance;
use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\AppInterface;
use App\Integrations\ServerManagers\HelmKubernetes\API\Application;
use App\Integrations\ServerManagers\HelmKubernetes\API\Job;
use App\Integrations\ServerManagers\HelmKubernetes\API\KubernetesNamespace;
use App\Integrations\ServerManagers\HelmKubernetes\API\Secret;
use App\Integrations\ServerManagers\HelmKubernetes\Services\DomainMiddlewareService;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
// Driver-agnostic: existsOrganization()/addOrganization()/deleteOrganization()
// only depend on $this->namespace having isActive()/create()/remove(), which
// both drivers' KubernetesNamespace classes implement identically.
use App\Integrations\ServerManagers\Rancher\Services\OrganizationServices;
use App\Organization;
use App\OrgServer;
use App\Support\Facades\Application as ApplicationFacade;

class HelmKubernetesWebInterface implements AppInterface, OrganizationInterface
{
    use OrganizationServices;

    private $organization;

    private $namespace;

    private $application;

    private $secret;

    public function __construct(
        private OrgServer $server,
        private ?AppInstance $app_instance = null,
    ) {
        $this->organization = $server->organization;

        $this->namespace = new KubernetesNamespace($this->organization, $server);
        $this->application = new Application($this->organization, $server);
        $this->secret = new Secret($this->organization, $server);
    }

    // Make sure the image pull secret required by the app instance's version
    // exists in the organization's namespace before the app is deployed
    public function ensurePullSecret()
    {
        $version = $this->app_instance->version;

        if (! $version || ! $version->requiresPullSecret()) {
            return true;
        }

        $this->secret->ensure($this->organization->slug, $version->pullSecret);

        return true;
    }

    public function exists()
    {
        $app = new Application($this->organization, $this->server);

        if (ApplicationFacade::profile($this->app_instance->application->slug)->activationType() === 'job') {
            $charts = ApplicationFacade::instance($this->app_instance->parent)->charts();
        } else {
            $charts = ApplicationFacade::instance($this->app_instance)->charts();
        }

        foreach ($charts as $chart) {
            $is_active = $app->isActive($this->app_instance, $chart);

            if ($is_active === 1 || $is_active === 2) {
                return true;
            }
        }

        return false;
    }

    public function notFoundMessage(): string
    {
        return __('messages.exception.app_not_found_rancher');
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
        if ($this->existsOrganization()) {
            $this->ensurePullSecret();

            $charts = ApplicationFacade::instance($this->app_instance)->charts();

            foreach ($charts as $chart) {
                if ($this->application->isActive($this->app_instance, $chart) === 1) {
                    $this->update();
                } else {
                    $this->application->create($this->app_instance, $chart);

                    $this->app_instance->refresh();
                }
            }

            return true;
        }

        $this->addOrganization();
    }

    public function update()
    {
        $this->ensurePullSecret();

        $charts = ApplicationFacade::instance($this->app_instance)->charts();

        foreach ($charts as $chart) {
            $is_active = $this->application->isActive($this->app_instance, $chart);

            if ($is_active === 1 || $is_active === 3) {
                $this->application->update($this->app_instance, $chart);

                $this->app_instance->refresh();
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
        $job = new Job($this->organization, $this->server);

        return $job->create($job_chart);
    }

    public function jobStatus(string $job_id)
    {
        $job = new Job($this->organization, $this->server);

        return $job->status($job_id);
    }
}
