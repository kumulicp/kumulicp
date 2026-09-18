<?php

namespace App\Integrations\Applications\CiviCRMStandalone;

use App\AppInstance;
use App\Integrations\Applications\EnvVar;
use App\Ldap\Actions\Dn;
use App\Support\Facades\Application;

class CiviCRMStandaloneEnvVars extends EnvVar
{
    public function get(AppInstance $app_instance)
    {
        if (config('account_manager.driver') !== 'ldap') {
            return [];
        }

        $app_instance = Application::instance($app_instance);
        $organization = $app_instance->organization;
        $base_dn = Dn::create($organization);
        $bind_dn = 'cn=admin,'.$base_dn;

        $admin_group = $app_instance->name.'-admin';
        $staff_group = $app_instance->name.'-staff';

        $scheme = config('ldap.connections.default.use_ssl') ? 'ldaps' : 'ldap';
        $host = config('ldap.connections.default.hosts.0');
        $port = config('ldap.connections.default.port');

        return [
            'USE_LDAP' => 'true',
            'CIVICRM_LDAP_URI' => "{$scheme}://{$host}:{$port}",
            'CIVICRM_LDAP_VERIFY_CERT' => config('ldap.connections.default.use_ssl') ? 'true' : 'false',
            'CIVICRM_LDAP_BASE_DN' => $base_dn,
            'CIVICRM_LDAP_BIND_USER' => $bind_dn,
            'CIVICRM_LDAP_BIND_PASS' => $organization->secretpw,
            'CIVICRM_LDAP_USER_LOOKUP' => 'inetOrgPerson',
            'CIVICRM_LDAP_GROUP_LOOKUP' => 'groupOfNames',
            // Restrict logins to members of this app instance's own LDAP groups (see App\Jobs\Applications\AddLdapGroups),
            // rather than any user in the org's LDAP tree -- keeps LDAP login scoped to kumulicp's own app-role grants.
            'CIVICRM_LDAP_GROUP_REQUIRE' => "{$admin_group}\n{$staff_group}",
            'CIVICRM_LDAP_GROUP_ROLES' => "{$admin_group} | admin\n{$staff_group} | staff",
        ];
    }
}
