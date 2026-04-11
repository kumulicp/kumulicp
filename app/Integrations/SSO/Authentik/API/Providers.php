<?php

namespace App\Integrations\SSO\Authentik\API;

use App\AppInstance;
use App\Integrations\SSO\Authentik\Authentik;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Providers extends Authentik
{
    private function provider_name(AppInstance $app_instance)
    {
        $this->resetClient();

        return "{$app_instance->id}-{$this->organization->slug}-{$app_instance->name}";
    }

    public function exists(AppInstance $app_instance)
    {
        $this->resetClient();
        if ($provider_id = $app_instance->setting('sso.provider_obj.pk')) {
            $provider = $this->get($this->basePath().'/api/v3/providers/oauth2/'.$provider_id.'/');

            return ! is_null(Arr::get($provider, 'content.pk', null));
        } else {
            $provider = $this->find($app_instance);

            return Arr::get($provider, 'name', null) === $this->provider_name($app_instance);
        }
    }

    public function find(AppInstance $app_instance)
    {
        $this->resetClient();
        $provider = $this->get($this->basePath().'/api/v3/providers/oauth2/', ['name' => $this->provider_name($app_instance)]);

        return Arr::get($provider, 'content.results.0');
    }

    public function create(AppInstance $app_instance)
    {
        $this->resetClient();
        $app = Application::get($app_instance->application->slug);
        $app_instance = Application::instance($app_instance);
        $server = $app_instance->server('sso')->server;
        $generator = new ComputerPasswordGenerator;

        $redirect_path = Arr::get($app, 'sso_redirect_path.path', null);
        $redirect_uri = [
            'matching_mode' => $redirect_path ? Arr::get($app, 'sso_redirect_path.matching_mode') : 'regex',
            'url' => $redirect_path ?? '.*',
        ];

        $client_id = Str::password(41, true, true, false);
        /*$generator
            ->setUppercase()
            ->setLowercase()
            ->setNumbers()
            ->setSymbols(false)
            ->setLength(41)
            ->generatePassword();*/

        $client_secret = Str::password(129, true, true, false);
        /*$generator
            ->setUppercase()
            ->setLowercase()
            ->setNumbers()
            ->setSymbols(false)
            ->setLength(129)
            ->generatePassword();*/

        $app_instance->updateSetting('sso_client_id', Crypt::encryptString($client_id));
        $app_instance->updateSetting('sso_client_secret', Crypt::encryptString($client_secret));

        $provider = $this->json()->post($this->basePath().'/api/v3/providers/oauth2/', [
            'name' => $this->provider_name($app_instance->get()),
            'authentication_flow' => $server->setting('authentication_flow'),
            'authorization_flow' => $server->setting('authorization_flow'),
            'invalidation_flow' => $server->setting('invalidation_flow'),
            'property_mappings' => $server->setting('property_mappings'),
            'client_type' => $server->setting('client_type'),
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'access_code_validity' => $server->setting('access_code_validity'),
            'access_token_validity' => $server->setting('access_token_validity'),
            'refresh_token_validity' => $server->setting('refresh_token_validity'),
            'include_claims_in_id_token' => true,
            'signing_key' => $server->setting('signing_key'),
            'encryption_key' => $server->setting('encryption_key'),
            'redirect_uris' => [$redirect_uri],
            'sub_mode' => $server->setting('sub_mode'),
            'issuer_mode' => $server->setting('issuer_mode'),
            'jwt_federation_sources' => $server->setting('jwt_federation_sources'),
            'jwt_federation_providers' => $server->setting('jwt_federation_providers'),
        ]);

        return Arr::get($provider, 'content');
    }

    public function remove(AppInstance $app_instance)
    {
        $this->resetClient();
        $provider_id = $app_instance->setting('sso.provider_obj.pk');

        try {
            $response = $this->delete($this->basePath()."/api/v3/providers/oauth2/$provider_id/");
        } catch (\Throwable $e) {
            $response = 'failed';
        }

        if (Arr::get($response, 'context', null) !== null) {
            return $response;
        }

        return true;
    }
}
