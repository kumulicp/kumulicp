<?php

namespace App\Integrations\Applications\Nextcloud;

use App\AppInstance;
use App\Integrations\Applications\EnvVar;
use App\Ldap\Actions\Dn;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\Crypt;

class NextcloudEnvVars extends EnvVar
{
    public function get(AppInstance $app_instance)
    {
        $app_instance = Application::instance($app_instance);
        $base_dn = Dn::create($app_instance->organization);
        $admin_dn = 'cn=admin,'.Dn::create($app_instance->organization);
        $group_dn = Dn::create($app_instance->organization, 'applications', $app_instance->name);
        $secretpw = $app_instance->organization->secretpw;
        $sso_server = $app_instance->server('sso')?->serverInfo();
        $sso_slug = $app_instance->setting('sso.slug') ?? "{$app_instance->id}-{$app_instance->organization->slug}-{$app_instance->name}";
        $standard = $app_instance->name.'-standard';
        $basic = $app_instance->name.'-basic';

        $group_base = "ou=groups,$base_dn\n$group_dn";

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
        ];

        $ldap_settings = env('ACCOUNTMANAGER_DRIVER') === 'ldap' ? [
            'USE_LDAP' => 'true',
            'LDAP_HOST' => env('LDAP_HOST'),
            'LDAP_PORT' => env('LDAP_PORT'),
            'LDAP_ADMIN' => $admin_dn,
            'LDAP_AGENT_PASSWORD' => $secretpw,
            'LDAP_BASE' => $base_dn,
            'LDAP_GROUP_BASE' => $group_base,
            'LOGIN_FILTER' => "(&(objectclass=inetOrgPerson)(|(memberof=cn=$standard,$group_dn)(memberof=cn=$basic,$group_dn))(|(cn=%uid)(mail=%uid)))",
            'USER_FILTER' => "(&(objectclass=inetOrgPerson)(|(memberof=cn=$standard,$group_dn)(memberof=cn=$basic,$group_dn)))",
        ] : [];

        $sso_settings = $app_instance->configuration('enable-sso') ? [
            'USE_SSO' => 'true',
            'OIDC_CLIENT_ID' => ($app_instance->configuration('enable-sso') && $app_instance->setting('sso_client_id')) ? Crypt::decryptString($app_instance->setting('sso_client_id')) : '',
            'OIDC_CLIENT_SECRET' => ($app_instance->configuration('enable-sso') && $app_instance->setting('sso_client_secret')) ? Crypt::decryptString($app_instance->setting('sso_client_secret')) : '',
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
