<?php

namespace App\Integrations\SSO\Authentik\API;

use App\AppInstance;
use App\Integrations\SSO\Authentik\Authentik;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class PolicyBindings extends Authentik
{
    public function list(AppInstance $app_instance)
    {
        Log::info(__('messages.api.authentik.log.policy_binding.listed', ['app' => $app_instance->name]), ['organization_id' => $this->organization->id]);

        $this->resetClient();
        $pk = $app_instance->setting('sso.pk');

        if (! $pk) {
            throw new \Exception('Authentik PK missing for '.$app_instance->name);
        }

        return $this->get($this->basePath().'/api/v3/policies/bindings/', ['target' => $pk])['content']['results'];
    }

    public function create(string $target, ?string $group = null, ?string $policy = null, ?string $user = null)
    {
        Log::info(__('messages.api.authentik.log.policy_binding.created', ['policy_binding' => $group, 'app' => $target]), ['organization_id' => $this->organization->id]);

        $this->resetClient();
        $policy_binding = $this->json()->post($this->basePath().'/api/v3/policies/bindings/', [
            'policy' => $policy,
            'group' => $group,
            'user' => $user,
            'target' => $target,
            'negate' => false,
            'enabled' => true,
            'order' => 0,
            'timeout' => 30,
            'failure_result' => true,
        ]);

        return Arr::get($policy_binding, 'content');
    }

    public function remove(string $target, string $id)
    {
        Log::info(__('messages.api.authentik.log.policy_binding.removed', ['policy_binding' => $id, 'app' => $target]), ['organization_id' => $this->organization->id]);

        $this->resetClient();

        return $this->delete($this->basePath().'/api/v3/policies/bindings/'.$id.'/');
    }
}
