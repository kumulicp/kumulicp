<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Charts\Middleware\MiddlewareChart;
use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Facades\Log;

class Middleware extends Rancher
{
    private string $middleware_name = 'middlewares';

    public function create(MiddlewareChart $chart)
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/traefik.io.middlewares';

        $data = $chart->values();

        $this->json()->post($url, $data);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.middleware_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    // primarily for adding regex redirects
    public function update(MiddlewareChart $chart)
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/traefik.io.middlewares/'.$namespace.'/'.$chart->name;

        $this->get($url);
        $response = $this->response();

        if ($response['status_code'] != 200) {
            return $this->create($chart);
        }

        $data = $response['content'];

        $data['spec'] = $chart->values()['spec'];

        $this->json()->put($url, $data);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.middleware_updated', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function remove(MiddlewareChart $chart)
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/traefik.io.middlewares/'.$namespace.'/'.$chart->name;

        $this->json()->ignoreErrorCode(404)->delete($url);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.middleware_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    // Check if the middleware is active(1), non existant (0), or transitioning (2)
    public function isActive(MiddlewareChart $chart): int
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/traefik.io.middlewares/'.$namespace.'/'.$chart->name;
        $this->ignoreErrorCode(404)->get($url);
        $response = $this->response();

        // if the resource doesn't exist return false
        if ($response['status_code'] === 404) {
            return 0;
        }

        // Check if namespace is transitioning;
        if ($response['content']['metadata']['state']['transitioning'] === true) {
            return 2;
        }

        // Check if the namespace is active
        if ($response['content']['metadata']['state']['name'] === 'active') {
            return 1;
        }

        // If no match, return non-existant
        return 0;
    }
}
