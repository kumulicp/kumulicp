<?php

namespace App\Integrations\Applications\CiviCRMStandalone\API;

use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandalone;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\Crypt;

class SsoSettings extends CiviCRMStandalone
{
    public function update(): static
    {
        $app_instance = Application::instance($this->app_instance);
        $server = $app_instance->server('sso')?->server;

        if (! $server) {
            return $this;
        }

        $sso_slug = $app_instance->setting('sso.slug')
            ?? "{$this->app_instance->id}-{$app_instance->organization->slug}-{$this->app_instance->name}";

        $client_id = $app_instance->setting('sso_client_id');
        $client_secret = $app_instance->setting('sso_client_secret');

        if (! $client_id || ! $client_secret) {
            return $this;
        }

        $this->action_description = __('messages.api.civicrm.sso.update_settings');

        // Reuses the bundled "keycloak" provider (generic OIDC, tagged
        // "Login") with its URLs fully overridden below.
        $this->form()->post($this->basePath().'/ajax/api4/OAuthClient/save', $this->data([
            'records' => [[
                'provider' => 'keycloak',
                'guid' => Crypt::decryptString($client_id),
                'secret' => Crypt::decryptString($client_secret),
                'is_active' => true,
                'options' => [
                    'urlAuthorize' => $server->address.'/application/o/authorize/',
                    'urlAccessToken' => $server->address.'/application/o/token/',
                    'urlResourceOwnerDetails' => $server->address.'/application/o/userinfo/',
                    'urlLogout' => $server->address.'/application/o/'.$sso_slug.'/end-session/',
                    'scopeSeparator' => ' ',
                    'scopes' => ['openid', 'email', 'profile'],
                ],
            ]],
            'match' => ['provider'],
        ]));

        $this->form()->post($this->basePath().'/ajax/api4/Setting/create', $this->data([
            'values' => [
                'oauth_login_mode' => $app_instance->configuration('oidc-mode'),
                'oauth_login_provisioning_enabled' => $app_instance->configuration('oidc-auto-provision'),
                'oauth_login_username_claim' => $app_instance->configuration('oidc-username-claim'),
                'oauth_login_email_claim' => $app_instance->configuration('oidc-email-claim'),
                'oauth_login_default_roles' => $app_instance->configuration('oidc-default-roles'),
            ],
        ]));

        return $this;
    }

    private function data(array $data)
    {
        return ['params' => json_encode($data)];
    }
}
