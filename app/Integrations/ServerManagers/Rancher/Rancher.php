<?php

namespace App\Integrations\ServerManagers\Rancher;

use App\Integrations\Integration;
use App\Organization;
use App\OrgServer;
use App\Services\OrgServerService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Rancher extends Integration
{
    public $name = 'Rancher';

    private $namespace;

    public function __construct(Organization $organization, public OrgServer $org_server)
    {
        parent::__construct($organization);
    }

    public function server_info()
    {
        $server_service = new OrgServerService($this->org_server);

        return $server_service->serverInfo();
    }

    public function basePath()
    {
        $server_info = $this->server_info();

        return $server_info->host;
    }

    public function setNamespace(?string $name = null)
    {
        $this->namespace = $name;
    }

    public function namespace()
    {
        if (! $this->namespace) {
            $this->namespace = $this->organization->slug;
        }

        return $this->namespace;
    }

    public function auth()
    {
        $server_info = $this->server_info();

        return [
            $server_info->api_key,
            $server_info->api_secret,
        ];
    }

    public function parseResponse($response)
    {
        $response = json_decode($response, true);

        if (Arr::get($response, 'type') === 'error' && in_array(Arr::get($response, 'status'), $this->statusErrorCodes())) {
            $this->setError(Arr::get($response, 'message'), Arr::get($response, 'status'), true);
        }

        $this->setResponse($response);
    }

    public function testing_fakes()
    {
        $fake_data = [];
        Arr::set($fake_data, 'metadata.state.transitioning', false);
        Arr::set($fake_data, 'metadata.state.name', 'active');

        Http::fake([
            'https://rancher.local.dev:8443/v1/namespaces/demo' => $fake_data,
            'https://rancher.local.dev:8443/v1/networking.k8s.io.ingresses/demo/nextcloud-1-redirect' => $fake_data,
            'https://rancher.local.dev:8443/v1/traefik.io.middlewares/demo/nextcloud-1-redirect' => $fake_data,
            'https://rancher.local.dev:8443/v1/catalog.cattle.io.apps/demo/nextcloud-1-nextcloud?action=uninstall' => $fake_data,
            'https://rancher.local.dev:8443/v1/networking.k8s.io.ingresses/demo/wordpress-2-redirect' => $fake_data,
            'https://rancher.local.dev:8443/v1/traefik.io.middlewares/demo/wordpress-2-redirect' => $fake_data,
            'https://rancher.local.dev:8443/v1/catalog.cattle.io.apps/demo/wordpress-2-wordpress?action=uninstall' => $fake_data,
            'https://rancher.local.dev:8443/v1/catalog.cattle.io.apps/demo/nextcloud-1-nextcloud' => $fake_data,
            'https://rancher.local.dev:8443/v1/catalog.cattle.io.apps/demo/wordpress-2-wordpress' => $fake_data,
            'https://rancher.local.dev:8443/v1/batch.jobs' => $fake_data,
        ]);
    }
}
