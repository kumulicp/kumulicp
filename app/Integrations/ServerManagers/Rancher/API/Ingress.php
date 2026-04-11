<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Charts\Ingress\IngressChart;
use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Ingress extends Rancher
{
    public function create(IngressChart $chart)
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/networking.k8s.io.ingresses';

        $data = $chart->values();

        if (count(Arr::get($data, 'spec.rules', []))) {
            $this->json()->post($url, $data);
            $response = $this->response();

            Log::info(__('messages.api.rancher.log.ingress_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

            return [
                'status' => 'success',
                'response' => $this->response_content(),
            ];
        }

        return [
            'status' => 'success',
            'response' => [],
        ];
    }

    // primarily for adding regex redirects
    public function update(IngressChart $chart)
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/networking.k8s.io.ingresses/'.$namespace.'/'.$chart->name;

        $this->get($url);
        $response = $this->response();

        if ($response['status_code'] != 200) {
            return $this->create($chart);
        }

        $data = $response['content'];

        $data['spec'] = $chart->values()['spec'];

        $this->json()->put($url, $data);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.ingress_updated', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function remove(IngressChart $chart)
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/networking.k8s.io.ingresses/'.$namespace.'/'.$chart->name;

        $this->json()->delete($url);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.ingress_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    // Check if the middleware is active(1), non existant (0), or transitioning (2)
    public function isActive(IngressChart $chart): int
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/networking.k8s.io.ingresses/'.$namespace.'/'.$chart->name;
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
