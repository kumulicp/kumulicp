<?php

namespace App\Integrations\Applications\Nextcloud;

use App\AppInstance;
use App\Integrations\Applications\EnvVar;
use App\Ldap\Actions\Dn;
use App\Ldap\LdapSupport;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\Crypt;

class NextcloudEnvVars extends EnvVar
{
    public function get(AppInstance $app_instance)
    {
        $app_instance = Application::instance($app_instance);

        // RancherWebInterface reroutes any child's add/update/delete to a
        // rebuild of the hub's own chart (see sharedPlanParent()), so
        // $app_instance here is always either the hub or a genuinely
        // standalone instance -- never a child directly. Having any
        // (non-deactivated) child at all is what distinguishes the two.
        $is_shared_hub = $app_instance->children()->notDeactivated()->exists();
        $global_base = config('ldap.connections.default.base_dn');

        $base_dn = $is_shared_hub ? $global_base : Dn::create($app_instance->organization);
        // Each org's own admin DN is ACL-restricted to just that org's
        // subtree -- widening LDAP_BASE above is useless for a shared hub
        // unless the bind account can actually read across every child
        // org's branch too. Use the dedicated, read-only "directory reader"
        // service account (o=server) for that -- never the app's own
        // default/rootDN connection, which has unrestricted read/write to
        // the entire directory and must never be handed to a third-party pod.
        $admin_dn = $is_shared_hub ? config('ldap.connections.directory_reader.username') : 'cn=admin,'.Dn::create($app_instance->organization);
        $group_dn = Dn::create($app_instance->organization, 'applications', $app_instance->name);
        $secretpw = $is_shared_hub ? config('ldap.connections.directory_reader.password') : $app_instance->organization->secretpw;
        $sso_server = $app_instance->server('sso')?->serverInfo();
        $sso_slug = $app_instance->setting('sso.slug') ?? "{$app_instance->id}-{$app_instance->organization->slug}-{$app_instance->name}";
        $standard = $app_instance->name.'-standard';
        $basic = $app_instance->name.'-basic';

        // For a shared hub, every registered child org's own per-org role
        // group (LdapSupport::getAppRoleGroup()) lives under that org's own
        // branch, not this instance's -- widen the group search base to the
        // whole shared LDAP tree so they're all visible.
        $group_base = $is_shared_hub ? "ou=groups,$global_base\n$global_base" : "ou=groups,$base_dn\n$group_dn";

        $default_settings = [
            'IS_PROXY' => 'true',
            'TRUSTED_PROXIES' => $app_instance->web_server->server->internal_address,
            'OVERWRITEPROTOCOL' => $app_instance->configuration('ingress-tls') ? 'https' : 'http',
            'OVERWRITECLIURL' => $app_instance->address(),
            'OVERWRITEWEBROOT' => '/',
            'OVERWRITEHOST' => $app_instance->domain(),
            'PHP_MEMORY_LIMIT' => $app_instance->configuration('nextcloud-php-memory-limit'),
            'PHP_UPLOAD_LIMIT' => $app_instance->configuration('nextcloud-php-upload-limit'),
            'PHP_OPCACHE_MEMORY_CONSUMPTION' => (string) $app_instance->configuration('nextcloud-php-opcache-memory-consumption'),
            'SHARED_INSTANCE' => $is_shared_hub ? 'true' : 'false',
        ];

        // For a shared hub, login is gated on the central access group
        // instead of this instance's own standard/basic role groups -- those
        // only ever covered the hub's own org, never a registered child's.
        $login_group_dn = $is_shared_hub ? LdapSupport::centralAccessGroup($app_instance->get())->getDn() : null;

        $ldap_settings = config('account_manager.driver') === 'ldap' ? [
            'USE_LDAP' => 'true',
            'LDAP_HOST' => config('ldap.connections.default.hosts.0'),
            'LDAP_PORT' => config('ldap.connections.default.port'),
            'LDAP_ADMIN' => $admin_dn,
            'LDAP_AGENT_PASSWORD' => $secretpw,
            'LDAP_BASE' => $base_dn,
            'LDAP_GROUP_BASE' => $group_base,
            'LOGIN_FILTER' => $is_shared_hub
                ? "(&(objectclass=inetOrgPerson)(memberof=$login_group_dn)(|(cn=%uid)(mail=%uid)))"
                : "(&(objectclass=inetOrgPerson)(|(memberof=cn=$standard,$group_dn)(memberof=cn=$basic,$group_dn))(|(cn=%uid)(mail=%uid)))",
            'USER_FILTER' => $is_shared_hub
                ? "(&(objectclass=inetOrgPerson)(memberof=$login_group_dn))"
                : "(&(objectclass=inetOrgPerson)(|(memberof=cn=$standard,$group_dn)(memberof=cn=$basic,$group_dn)))",
        ] : [];

        $sso_settings = $app_instance->configuration('enable-sso') ? [
            'USE_SSO' => 'true',
            'OIDC_CLIENT_ID' => $app_instance->setting('sso_client_id') ? Crypt::decryptString($app_instance->setting('sso_client_id')) : '',
            'OIDC_CLIENT_SECRET' => $app_instance->setting('sso_client_secret') ? Crypt::decryptString($app_instance->setting('sso_client_secret')) : '',
            'OIDC_DISCOVERY_URI' => $sso_server ? $sso_server->address.'/application/o/'.$sso_slug.'/.well-known/openid-configuration' : '',
            'OIDC_SCOPE' => $app_instance->configuration('oidc-scope'),
            'OIDC_END_SESSION_ENDPOINT_URI' => '',
            'OIDC_MAPPING_USER_ID' => $app_instance->configuration('oidc-mapping-user-id'),
            'OIDC_MAPPING_EMAIL' => $app_instance->configuration('oidc-mapping-email'),
            'OIDC_MAPPING_NAME' => $app_instance->configuration('oidc-mapping-email'),
            'OIDC_MULTIPLE_BACKENDS' => (string) $app_instance->configuration('oidc-multiple-backends'),
            'OIDC_AUTO_PROVISION' => $app_instance->configuration('oidc-auto-provision') ? 'true' : 'false',
        ] : [];

        return array_merge($default_settings, $ldap_settings, $sso_settings);
    }
}
