<?php

namespace App\Integrations\Applications\Wordpress;

use App\Actions\Apps\ApplicationUpdateJob;
use App\Actions\Apps\ApplicationUpgrade;
use App\Integrations\ServerManagers\Rancher\Charts\Job\WordpressJobChart;
use App\Ldap\Actions\Dn;
use App\Support\Facades\Action;
use Illuminate\Support\Facades\Crypt;

class WordpressRancherJobs extends WordpressJobChart
{
    public function updateDomain()
    {
        $this->run(
            command: ['/entrypoint.sh', '/update-domain.sh'],
            env: [
                ['name' => 'APP_URL', 'value' => $this->app_instance->address()],
                ['name' => 'USE_SSL', 'value' => Application::instance($this->app_instance)->configuration('ingress-tls') ? 'true' : 'false'],
            ]
        );

        Action::execute(new ApplicationUpgrade(app_instance: $this->app_instance, version: $this->app_instance->version, notify: false));

        foreach ($this->app_instance->children as $child) {
            Action::execute(new ApplicationUpdateJob($child, 'update_domain'));
        }

        return $this;
    }

    public function updateSettings()
    {
        $secretpw = $this->organization->parent_organization?->secretpw ?? $this->organization->secretpw;
        $ldap_group_dn = Dn::create($this->organization, 'applications', $this->app_instance->name);
        $ldap_admin_dn = 'cn=admin,'.Dn::create($this->organization);
        $ldap_users_dn = Dn::create($this->organization, 'users');
        $ldap_host = env('LDAP_HOST');
        $ldap_uri = "ldap://$ldap_admin_dn:$secretpw@$ldap_host/$ldap_users_dn";
        $no_reply_domain = explode('@', env('MAIL_FROM_ADDRESS'))[1];

        $app_instance = Application::instance($this->app_instance);
        $sso_server = $app_instance->server('sso')?->serverInfo();

        $sso_settings = $app_instance->configuration('enable-sso') ? [
            ['name' => 'USE_SSO', 'value' => 'true'],
            ['name' => 'OIDC_LOGIN_TYPE', 'value' => $app_instance->configuration('oidc-login-type')],
            ['name' => 'OIDC_CLIENT_ID', 'value' => $app_instance->setting('sso_client_id') ? Crypt::decryptString($app_instance->setting('sso_client_id')) : ''],
            ['name' => 'OIDC_CLIENT_SECRET', 'value' => $app_instance->setting('sso_client_secret') ? Crypt::decryptString($app_instance->setting('sso_client_secret')) : ''],
            ['name' => 'OIDC_CLIENT_SCOPE', 'value' => $app_instance->configuration('oidc-client-scope')],
            ['name' => 'OIDC_ENDPOINT_LOGIN_URL', 'value' => $sso_server?->address.'/application/o/authorize/'],
            ['name' => 'OIDC_ENDPOINT_USERINFO_URL', 'value' => $sso_server?->address.'/application/o/userinfo/'],
            ['name' => 'OIDC_ENDPOINT_TOKEN_URL', 'value' => $sso_server?->address.'/application/o/token/'],
            ['name' => 'OIDC_ENDPOINT_LOGOUT_URL', 'value' => $sso_server?->address.'/application/o/'.$app_instance->setting('sso.slug').'/end-session/'],
            ['name' => 'OIDC_ACR_VALUES', 'value' => ''],
        ] : [];

        $roles = [];
        foreach ($app_instance->roles(false) as $role) {
            $roles[$role->slug] = $role->app_slug($app_instance->get());
        }

        foreach ($app_instance->children as $child) {
            $child = Application::instance($child);
            foreach ($child->roles(false) as $role) {
                $roles[$role->slug] = $role->app_slug($child->get());
            }
        }

        $default_settings = [
            ['name' => 'APP_URL', 'value' => $this->app_instance->address()],
            ['name' => 'ACCOUNT_TOKEN', 'value' => $this->organization->api_token],
            ['name' => 'NO_REPLY_HOST', 'value' => env('MAIL_HOST')],
            ['name' => 'NO_REPLY_PORT', 'value' => env('MAIL_PORT')],
            ['name' => 'NO_REPLY_PASSWORD', 'value' => env('MAIL_PASSWORD')],
            ['name' => 'NO_REPLY_EMAIL', 'value' => env('MAIL_FROM_ADDRESS')],
            ['name' => 'NO_REPLY_DOMAIN', 'value' => $no_reply_domain],
            ['name' => 'LDAP_GROUP_DN', 'value' => $ldap_group_dn],
            ['name' => 'LDAP_URI', 'value' => $ldap_uri],
            ['name' => 'CP_URL', 'value' => env('APP_URL')],
            ['name' => 'USE_SSL', 'value' => $app_instance->configuration('ingress-tls') ? 'true' : 'false'],
            ['name' => 'ORG_TYPE', 'value' => $this->organization->type],
            ['name' => 'LDAP_GROUPS', 'value' => json_encode($roles)],
        ];

        $this->run(
            command: ['/entrypoint.sh', '/wordpress-settings.sh'],
            env: array_merge($default_settings, $sso_settings),
        );

        return $this;
    }

    public function updateLdap()
    {

        return $this;
    }
}
