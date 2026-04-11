<?php

namespace App\Integrations\SSO\Authentik\API;

use App\AppInstance;
use App\Exceptions\SSONotReadyException;
use App\Integrations\SSO\Authentik\Authentik;
use App\Ldap\Actions\Dn;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

class Groups extends Authentik
{
    public function exists(AppInstance $app_instance)
    {
        $this->resetClient();
        $app = Application::instance($app_instance);
        $roles = $app->allRoles();
        $group_list = [];

        foreach ($roles as $role) {
            $authentik_group = $this->get($this->basePath().'/api/v3/core/groups/', [
                'attributes' => json_encode([
                    'distinguishedName' => Dn::create($this->organization, 'applications', [$role->app_slug($app_instance), $app_instance->name]),
                ],
                )]);
            $group_info = Arr::get($authentik_group, 'content.results.0', null);

            if (! $group_info) {
                return false;
            }
        }

        return true;
    }

    public function list(AppInstance $app_instance)
    {
        $this->resetClient();
        $app = Application::instance($app_instance);
        $roles = $app->allRoles();
        $group_list = [];

        foreach ($roles as $role) {
            $this->resetClient();
            $authentik_group = $this->get($this->basePath().'/api/v3/core/groups/', [
                'attributes' => json_encode([
                    'distinguishedName' => Dn::create($this->organization, 'applications', [$role->app_slug($app_instance), $app_instance->name]),
                ]
                )]);
            $group_info = Arr::get($authentik_group, 'content.results.0', null);

            if ($group_info) {
                $group_list[] = $group_info;
            } else {
                throw new SSONotReadyException(__('messages.exception.sso_not_ready', ['reason' => __('messages.sso.denied.groups_missing')]));
            }
        }

        return $group_list;
    }
}
