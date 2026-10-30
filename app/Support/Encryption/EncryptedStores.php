<?php

namespace App\Support\Encryption;

/**
 * Every place the app keeps data encrypted with APP_KEY. `keys:rotate` and
 * `keys:check` read this list, and a test fails when a file that encrypts data
 * isn't registered under `sources`, so a new encrypted column can't be forgotten.
 */
class EncryptedStores
{
    /**
     * Columns that always hold a single encrypted payload (a cast, or Crypt called on the whole value).
     *
     * @return array<int, array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'organizations', 'column' => 'secretpw'],
            ['table' => 'sso_providers', 'column' => 'client_secret'],
            ['table' => 'sso_providers', 'column' => 'access_token'],
            ['table' => 'sso_providers', 'column' => 'refresh_token'],
            ['table' => 'repo_secrets', 'column' => 'password'],
            ['table' => 'servers', 'column' => 'api_key'],
            ['table' => 'servers', 'column' => 'api_secret'],
            ['table' => 'app_instances', 'column' => 'api_password'],
        ];
    }

    /**
     * JSON columns that can hold encrypted payloads at paths that aren't fixed
     * (plan secrets, secret feature settings, password configurations, SSO and
     * database credentials). Every string in them that has the shape of an
     * encrypted payload is treated as one.
     *
     * @return array<int, array{table: string, column: string}>
     */
    public static function jsonColumns(): array
    {
        return [
            ['table' => 'organizations', 'column' => 'settings'],
            ['table' => 'servers', 'column' => 'settings'],
            ['table' => 'app_instances', 'column' => 'settings'],
            ['table' => 'app_plans', 'column' => 'settings'],
            ['table' => 'plans', 'column' => 'settings'],
        ];
    }

    /**
     * Files under app/ that encrypt or decrypt data, relative to the app path.
     * Register the file here, and its columns above, when adding new encrypted data.
     *
     * @return array<int, string>
     */
    public static function sources(): array
    {
        return [
            'AppInstance.php',
            'Casts/EmptyStringAsNullEncrypted.php',
            'Console/Commands/EncryptLegacyServerCredentials.php',
            'Integrations/Applications/Nextcloud/NextcloudEnvVars.php',
            'Integrations/Applications/Nextcloud/NextcloudRancherJobs.php',
            'Integrations/Applications/Wordpress/WordpressRancherJobs.php',
            'Integrations/SSO/Authentik/API/Providers.php',
            'Integrations/ServerManagers/Rancher/Charts/Chart.php',
            'Integrations/ServerManagers/Rancher/Charts/NextcloudChart.php',
            'Organization.php',
            'RepoSecret.php',
            'Server.php',
            'Services/Application/AppPlanService.php',
            'Services/Application/PlanSecretsService.php',
            'Services/ApplicationService.php',
            'Support/Encryption/KeyRing.php',
            'Support/SecretValue.php',
            'SsoProvider.php',
        ];
    }
}
