<?php

namespace App\Integrations\SSO\Authentik;

use App\Integrations\Integration;
use App\Organization;
use App\OrgServer;
use Illuminate\Support\Facades\Log;

class Authentik extends Integration
{
    public function __construct(Organization $organization, public OrgServer $org_server)
    {
        parent::__construct($organization);
    }

    public function basePath()
    {
        return $this->org_server->server->address;
    }

    public function headers()
    {
        return [
            'Authorization' => "{$this->org_server->server->api_key} {$this->org_server->server->api_secret}",
            'Accept' => 'application/json',
        ];
    }

    public function parseResponse($response)
    {
        if ($this->status_code == 400) {
            $response = json_decode($response, true);
            $error_message = __('messages.exception.api_error', ['description' => $this->action_description, 'code' => $response['code'], 'message' => $response['message']]);
            Log::critical($error_message, ['organization_id' => $this->organization->id]);

            $this->setError($error_message, $response['code']);
        } else {
            $this->setResponse(json_decode($response, true));
        }
    }
}
