<?php

namespace App\Integrations\SSO\Authentik\API;

use App\AppInstance;
use App\Integrations\SSO\Authentik\Authentik;
use Illuminate\Support\Arr;

class Applications extends Authentik
{
    public function exists(AppInstance $app_instance)
    {
        $this->resetClient();
        $app_slug = "{$app_instance->id}-{$this->organization->slug}-{$app_instance->name}";
        $provider = $this->json()->ignoreErrorCode(404)->get($this->basePath().'/api/v3/core/applications/'.$app_slug.'/');

        return ! is_null(Arr::get($provider, 'content.slug', null));
    }

    public function create(AppInstance $app_instance, int $provider_id)
    {
        $this->resetClient();
        $app = $this->json()->post($this->basePath().'/api/v3/core/applications/', [
            'name' => $app_instance->label,
            'slug' => "{$app_instance->id}-{$this->organization->slug}-{$app_instance->name}",
            'backchannel_providers' => [],
            'provider' => $provider_id,
            'open_in_new_tab' => true,
            'meta_launch_url' => $app_instance->admin_address(),
            'meta_description' => $this->organization->name,
            'meta_publisher' => $this->organization->name,
            'policy_engine_mode' => 'any',
            'group' => $this->organization->name,
        ]);

        return $app['content'];
    }

    public function update(AppInstance $app_instance)
    {
        $this->resetClient();
        $app_slug = $app_instance->setting('sso.slug') ?? "{$app_instance->id}-{$this->organization->slug}-{$app_instance->name}";

        $app = $this->json()->put($this->basePath().'/api/v3/core/applications/'.$app_slug.'/', [
            'name' => $app_instance->label,
            'slug' => "$app_slug",
            'open_in_new_tab' => true,
            'meta_launch_url' => $app_instance->admin_address(),
            'meta_description' => $this->organization->name,
            'meta_publisher' => $this->organization->name,
            'policy_engine_mode' => 'any',
            'group' => $this->organization->name,
        ]);

        return $app['content'];
    }

    public function remove(AppInstance $app_instance)
    {
        $this->resetClient();
        $app_slug = $app_instance->setting('sso.slug');

        try {
            $response = $this->delete($this->basePath()."/api/v3/core/applications/$app_slug/");
        } catch (\Throwable $e) {
            $response = 'failed';
        }

        if (Arr::get($response, 'context', null) !== null) {
            return $response;
        }

        return true;
    }
}
