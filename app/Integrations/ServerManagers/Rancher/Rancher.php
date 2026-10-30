<?php

namespace App\Integrations\ServerManagers\Rancher;

use App\Integrations\Integration;
use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Integrations\ServerManagers\Rancher\API\Secret;
use App\Integrations\ServerManagers\Rancher\Support\RancherClusterCredentials;
use App\Organization;
use App\OrgServer;
use App\Services\OrgServerService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Rancher extends Integration
{
    public $name = 'Rancher';

    private $namespace;

    private $raw_response = false;

    private ?KubernetesApiClient $api = null;

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

    /**
     * The Kubernetes API of the cluster Rancher manages, reached through
     * Rancher's cluster proxy (see RancherClusterCredentials).
     */
    public function api(): KubernetesApiClient
    {
        $server = $this->org_server->server;

        return $this->api ??= new KubernetesApiClient($server, new RancherClusterCredentials($server));
    }

    public function secret(): Secret
    {
        return new Secret($this->organization, $this->org_server);
    }

    // Skips JSON decoding for the next request so plain-text responses
    // (e.g. pod logs, which aren't guaranteed to be valid JSON) survive intact.
    public function raw()
    {
        $this->raw_response = true;

        return $this;
    }

    public function parseResponse($response)
    {
        if ($this->raw_response) {
            $this->raw_response = false;
            $this->setResponse($response);

            return;
        }

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
