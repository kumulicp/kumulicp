<?php

namespace App\Integrations\ServerManagers\Kubernetes\Interfaces;

use App\AppInstance;
use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\AppInterface;
use App\Integrations\ServerManagers\Kubernetes\API\Application;
use App\Integrations\ServerManagers\Kubernetes\API\Job;
use App\Integrations\ServerManagers\Kubernetes\API\KubernetesNamespace;
use App\Integrations\ServerManagers\Kubernetes\API\Secret;
use App\Integrations\ServerManagers\Kubernetes\Services\DomainMiddlewareService;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
// Driver-agnostic: existsOrganization()/addOrganization()/deleteOrganization()
// only depend on $this->namespace having isActive()/create()/remove(), which
// both drivers' KubernetesNamespace classes implement identically.
use App\Integrations\ServerManagers\Rancher\Services\OrganizationServices;
use App\Organization;
use App\OrgServer;
use App\Support\Facades\Application as ApplicationFacade;

/**
 * Built to be extended: a driver layered on Kubernetes (e.g. Rancher)
 * overrides the make*() factories to swap in its own API classes, and any
 * of the public methods where its behaviour differs.
 */
class KubernetesWebInterface implements AppInterface, OrganizationInterface
{
    use OrganizationServices;

    protected $organization;

    protected $namespace;

    protected $application;

    protected $secret;

    public function __construct(
        protected OrgServer $server,
        protected ?AppInstance $app_instance = null,
    ) {
        $this->organization = $server->organization;

        $this->namespace = $this->makeNamespace();
        $this->application = $this->makeApplication();
        $this->secret = $this->makeSecret();
    }

    protected function makeNamespace(): KubernetesNamespace
    {
        return new KubernetesNamespace($this->organization, $this->server);
    }

    protected function makeApplication(): Application
    {
        return new Application($this->organization, $this->server);
    }

    protected function makeSecret(): Secret
    {
        return new Secret($this->organization, $this->server);
    }

    protected function makeJob(): Job
    {
        return new Job($this->organization, $this->server);
    }

    protected function makeDomainMiddleware(): DomainMiddlewareService
    {
        return new DomainMiddlewareService($this->organization, $this->server, $this->app_instance);
    }

    // Make sure the image registry credentials required by the app instance's version
    // exists in the organization's namespace before the app is deployed
    public function ensureImageRegistryAuth()
    {
        $version = $this->app_instance->version;

        if (! $version || ! $version->requiresImageRegistryAuth()) {
            return true;
        }

        $this->secret->ensure($this->organization->slug, $version->imageRegistry);

        return true;
    }

    public function exists()
    {
        foreach ($this->chartsForStatus() as $chart) {
            $is_active = $this->application->isActive($this->app_instance, $chart);

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
        $app_instance = $this->statusInstance();

        return $this->application->retrieve($app_instance, ApplicationFacade::instance($app_instance)->charts()[0]);
    }

    public function isActive()
    {
        return $this->checkStatus()['active'];
    }

    public function checkStatus(): array
    {
        $active = true;
        $pending = false;
        $labels = [];

        foreach ($this->chartsForStatus() as $chart) {
            $status = $this->application->isActive($this->app_instance, $chart);
            $labels[] = $chart->chartName().': '.$this->statusLabel($status);

            if ($status !== 1) {
                $active = false;
            }

            if ($status === 2) {
                $pending = true;
            }
        }

        return ['active' => $active, 'pending' => $pending, 'message' => implode(', ', $labels)];
    }

    /**
     * Whether this driver can clear a release left pending by a killed
     * process. Checked by ApplicationUpgrade before it calls
     * recoverStuckRelease(); a driver whose releases are managed elsewhere
     * returns false.
     */
    public function canRecoverStuckRelease(): bool
    {
        return true;
    }

    /**
     * Deletes the release secret for any chart currently stuck pending --
     * see Application::deleteStuckReleaseSecrets(). Called when a task's
     * completion check finds the release has sat pending longer than the
     * long queue's worker timeout, implying the process that ran `helm
     * upgrade --wait` was killed (e.g. the container restarted) before it
     * could finish, rather than the operation genuinely still running.
     */
    public function recoverStuckRelease(): void
    {
        foreach ($this->chartsForStatus() as $chart) {
            if ($this->application->isActive($this->app_instance, $chart) === 2) {
                $this->application->deleteStuckReleaseSecrets($chart);
            }
        }
    }

    // The instance whose releases report this one's status: a job runs inside its parent's release.
    protected function statusInstance(): AppInstance
    {
        if (ApplicationFacade::profile($this->app_instance->application->slug)->activationType($this->app_instance) === 'job') {
            return $this->app_instance->parent;
        }

        return $this->app_instance;
    }

    protected function chartsForStatus(): array
    {
        return ApplicationFacade::instance($this->statusInstance())->charts();
    }

    protected function statusLabel(int $status): string
    {
        return match ($status) {
            1 => 'deployed',
            2 => 'pending',
            3 => 'failed',
            default => 'not found',
        };
    }

    public function add()
    {
        if ($this->existsOrganization()) {
            $this->ensureImageRegistryAuth();

            $charts = ApplicationFacade::instance($this->app_instance)->charts();

            foreach ($charts as $chart) {
                if ($this->application->isActive($this->app_instance, $chart) === 1) {
                    $this->update();
                } else {
                    $this->assertSuccessful($this->application->create($this->app_instance, $chart), $chart);

                    $this->app_instance->refresh();
                }
            }

            return true;
        }

        $this->addOrganization();
    }

    public function update()
    {
        $this->ensureImageRegistryAuth();

        $charts = ApplicationFacade::instance($this->app_instance)->charts();

        foreach ($charts as $chart) {
            $is_active = $this->application->isActive($this->app_instance, $chart);

            // 0 = not found -- e.g. release record deleted/lost -- falls back
            // to a fresh install rather than silently no-op'ing forever.
            if (in_array($is_active, [0, 1, 3], true)) {
                $this->assertSuccessful($this->application->update($this->app_instance, $chart), $chart);

                $this->app_instance->refresh();
                $this->updateRedirectDomains();
            }
        }

        return true;
    }

    // create()/update() return ['status' => 'failed', 'response' => <helm
    // error text>] rather than throwing (they're also used for polling via
    // isActive()), so callers that need the queue/task error-reporting
    // machinery to see a helm failure (RunAction::failed() -> task's
    // error_message + Laravel log) must check the result explicitly.
    protected function assertSuccessful(array $result, $chart): void
    {
        if ($result['status'] === 'failed') {
            throw new \Exception(__('messages.exception.helm_operation_failed', [
                'chart' => $chart->chartName(),
                'error' => $result['response'],
            ]));
        }
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
        $this->makeDomainMiddleware()->update();
    }

    public function runJob(JobChart $job_chart)
    {
        return $this->makeJob()->create($job_chart);
    }

    public function jobStatus(string $job_id)
    {
        return $this->makeJob()->status($job_id);
    }
}
