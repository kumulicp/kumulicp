<?php

namespace App\Integrations\Applications\CiviCRMStandalone\API;

use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandalone;
use App\Ldap\Actions\Dn;
use App\Support\Facades\Application;

class LdapSettings extends CiviCRMStandalone
{
    public function update(): static
    {
        $app_instance = Application::instance($this->app_instance);
        $organization = $app_instance->organization;

        $base_dn = Dn::create($organization);
        $bind_dn = 'cn=admin,'.$base_dn;

        $roles = $app_instance->roles(false);
        $group_names = $roles->map(fn ($role) => $role->app_slug($this->app_instance))->all();
        $group_roles = $roles->map(fn ($role) => $role->app_slug($this->app_instance).'|'.$role->slug)->implode("\n");

        $path = $this->basePath().'/ajax/api4/Setting/create';

        $this->action_description = __('messages.api.civicrm.ldap.update_settings');

        $this->form()->post($path, $this->data([
            'values' => [
                'ldap_uri' => 'ldap://'.config('ldap.connections.default.hosts.0').':'.config('ldap.connections.default.port'),
                'ldap_verify_cert' => false,
                'ldap_base_dn' => $base_dn,
                'ldap_bind_user' => $bind_dn,
                'ldap_bind_pass' => $organization->secretpw,
                'ldap_user_lookup' => ['inetOrgPerson'],
                'ldap_group_lookup' => ['groupOfNames'],
                'ldap_group_require' => implode("\n", $group_names) ?: '*',
                'ldap_group_roles' => $group_roles,
            ],
        ]));

        return $this;
    }

    private function data(array $data)
    {
        return ['params' => json_encode($data)];
    }
}
