<?php

namespace App\Integrations\Applications\Nextcloud;

use App\Actions\Apps\ApplicationUpdate;
use App\Integrations\ServerManagers\Rancher\Charts\Job\NextcloudJobChart;
use App\Ldap\Actions\Dn;
use App\Support\Facades\Action;

class NextcloudRancherJobs extends NextcloudJobChart
{
    public function updateDomain()
    {
        $NEXTCLOUD_TRUSTED_DOMAINS = $this->app_instance->domain();
        $this->run(
            command: ['su', '-p', 'www-data', '-s', '/bin/sh', '-c'],
            args: [
                "php /var/www/html/occ config:system:set overwrite.cli.url --value=\"https://$NEXTCLOUD_TRUSTED_DOMAINS\"",
                "php /var/www/html/occ config:system:set trusted_domains 1 --value=\"$NEXTCLOUD_TRUSTED_DOMAINS\"]",
            ],
            // env: [
            //     ['name' => 'IS_PROXY', 'value' => 'true'],
            //     ['name' => 'NEXTCLOUD_TRUSTED_DOMAINS', 'value' => $this->app_instance->domain()],
            //     ['name' => 'REWRITE_BASE', 'value' => '/'],
            // ]
        );

        Action::execute(new ApplicationUpdate($this->app_instance));

        return $this;
    }

    public function updateSettings()
    {
        $NEXTCLOUD_TRUSTED_DOMAINS = $this->app_instance->domain();
        $sso_slug = $app_instance->setting('sso.slug') ?? "{$app_instance->id}-{$this->organization->slug}-{$app_instance->name}";
        $this->run(
            command: ['/bin/sh', '-c'],
            args: [
                "php /var/www/html/occ config:system:set overwrite.cli.url --value=\"https://$NEXTCLOUD_TRUSTED_DOMAINS\" \n
                php /var/www/html/occ config:system:set trusted_domains 1 --value=\"$NEXTCLOUD_TRUSTED_DOMAINS\"",
            ],
            env: [
                ['name' => 'IS_PROXY', 'value' => 'true'],
                ['name' => 'NEXTCLOUD_TRUSTED_DOMAINS', 'value' => $this->app_instance->domain()],
                ['name' => 'REWRITE_BASE', 'value' => '/'],
                ['name' => 'USE_SSO', 'value' => (string) $app_instance->configuration('enable-sso')],
                ['name' => 'OIDC_CLIENT_ID', 'value' => $app_instance->configuration('enable-sso') ? Crypt::decryptString($app_instance->setting('sso_client_id')) : ''],
                ['name' => 'OIDC_CLIENT_SECRET', 'value' => $app_instance->configuration('enable-sso') ? Crypt::decryptString($app_instance->setting('sso_client_secret')) : ''],
                ['name' => 'OIDC_DISCOVERY_URI', 'value' => $sso_server ? $sso_server->address.'/application/o/'.$sso_slug.'/.well-known/openid-configuration' : ''],
                ['name' => 'OIDC_SCOPE', 'value' => $app_instance->configuration('oidc-scope')],
                ['name' => 'OIDC_END_SESSION_ENDPOINT_URI', 'value' => ''],
                ['name' => 'OIDC_MAPPING_USER_ID', 'value' => $app_instance->configuration('oidc-mapping-user-id')],
                ['name' => 'OIDC_MAPPING_EMAIL', 'value' => $app_instance->configuration('oidc-mapping-email')],
                ['name' => 'OIDC_MAPPING_NAME', 'value' => $app_instance->configuration('oidc-mapping-name')],
                ['name' => 'OIDC_MULTIPLE_BACKENDS', 'value' => (string) $app_instance->configuration('oidc-multiple-backends')],
                ['name' => 'OIDC_AUTO_PROVISION', 'value' => $app_instance->configuration('oidc-auto-provision') ? 'true' : 'false'],
            ]
        );
        // $this->updateLdap();

        return $this;
    }

    public function updateLdap()
    {
        $base_dn = Dn::create($this->organization);
        $group_dn = Dn::create($this->organization, 'applications', $this->app_instance->name);
        $standard = $this->app_instance->name.'-standard';
        $basic = $this->app_instance->name.'-basic';

        $this->run(
            command: ['/set-ldap.sh'],
            env: [
                ['name' => 'USE_LDAP', 'value' => 'true'],
                ['name' => 'LDAP_HOST', 'value' => env('LDAP_HOST')],
                ['name' => 'LDAP_PORT', 'value' => env('LDAP_PORT')],
                ['name' => 'LDAP_ADMIN', 'value' => "cn=admin,$base_dn"],
                ['name' => 'LDAP_AGENT_PASSWORD', 'value' => $this->organization->secretpw],
                ['name' => 'LDAP_BASE', 'value' => $base_dn],
                ['name' => 'LOGIN_FILTER', 'value' => "(&(objectclass=inetOrgPerson)(|(memberof=$group_dn)(memberof=cn=$standard,$group_dn)(memberof=cn=$basic,$group_dn))(|(cn=%uid)(mail=%uid)))"],
                ['name' => 'USER_FILTER', 'value' => "(&(objectclass=inetOrgPerson)(|(memberof=$group_dn)(memberof=cn=$standard,$group_dn)(memberof=cn=$basic,$group_dn)))"],
            ]
        );

        return $this;
    }
}
