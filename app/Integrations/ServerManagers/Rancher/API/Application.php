<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\AppInstance;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Application extends Rancher
{
    public function create(AppInstance $app_instance, HelmChart $chart)
    {
        $app = $app_instance->application;
        $app_name = $app->slug;
        $namespace = $chart->namespace();
        $address = $this->org_server->server->address;
        $repo_name = $chart->repo_name ?? $app_instance->version->setting('helm_repo_name');

        $url = $address."/v1/catalog.cattle.io.clusterrepos/$repo_name?action=install";

        // instantiate nextcloud
        $data = $chart->buildChart();

        $response = $this->json()->post($url, $data);

        Log::info(__('messages.api.rancher.log.app_created', ['app' => $app->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function update(AppInstance $app_instance, HelmChart $chart)
    {
        $app = $app_instance->application;
        $app_name = $app->slug;
        $namespace = $chart->namespace();
        $application = $app_instance->application;
        $address = $this->org_server->server->address;
        $repo_name = $chart->repo_name ?? $app_instance->version->setting('helm_repo_name');

        $url = $address."/v1/catalog.cattle.io.clusterrepos/$repo_name?action=upgrade";

        $data = $chart->buildChart($app_instance);
        $response = $this->json()->post($url, $data);

        Log::info(__('messages.api.rancher.log.app_created', ['app' => $app->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function retrieve(AppInstance $app_instance, HelmChart $chart)
    {
        $namespace = $chart->namespace();
        $application = $app_instance->application;
        $address = $this->org_server->server->address;
        $app_name = $chart->chartName();

        $url = $address.'/v1/catalog.cattle.io.apps/'.$namespace.'/'.$app_name;

        $response = $this->get($url);

        Log::info(__('messages.api.rancher.log.app_retrieved', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function remove(AppInstance $app_instance, HelmChart $chart)
    {
        $app_name = $chart->chartName();

        $namespace = $chart->namespace();
        $application = $app_instance->application;
        $address = $this->org_server->server->address;

        $data = $chart->buildChart($app_instance);

        $url = "$address/v1/catalog.cattle.io.apps/$namespace/$app_name?action=uninstall";

        $response = $this->json()->ignoreErrorCode(404)->post($url, $data);

        Log::info(__('messages.api.rancher.log.app_deleted', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function isActive(AppInstance $app_instance, HelmChart $chart): int
    {
        $app_name = $chart->chartName();
        $namespace = $chart->namespace();
        $address = $this->org_server->server->address;

        $url = $address.'/v1/catalog.cattle.io.apps/'.$namespace.'/'.$app_name;
        $this->ignoreErrorCode(404)->get($url);
        $response = $this->response();

        // if the resource doesn't exist return false
        if ($response['status_code'] === 404) {
            return 0;
        }

        // Check if persistent volume claim is transitioning;
        if (Arr::get($response, 'content.metadata.state.transitioning') === true) {
            return 2;
        }

        // Check if the persistent volume claim is active
        if (Arr::get($response, 'content.metadata.state.name') === 'deployed') {
            return 1;
        }

        // Check if the persistent volume claim is active
        if (Arr::get($response, 'content.metadata.state.name') === 'failed') {
            return 3;
        }

        // If no match, return non-existant
        return 0;
    }
}
