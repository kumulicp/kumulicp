<?php

namespace Database\Seeders;

use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run()
    {
        DB::table('applications')->insert([
            [
                'id' => 1,
                'name' => 'Nextcloud',
                'slug' => 'nextcloud',
                'short_description' => 'Nextcloud',
                'description' => 'Nextcloud',
                'domain_option' => 'base',
                'parent_app_id' => 0,
                'enabled' => 1,
                'category' => 'File Sharing & Collaboration',
                'access_type' => 'standard',
            ],
            [
                'id' => 2,
                'name' => 'Wordpress',
                'slug' => 'wordpress',
                'short_description' => 'Wordpress',
                'description' => 'Wordpress',
                'domain_option' => 'base',
                'parent_app_id' => 0,
                'enabled' => 1,
                'category' => 'Website Builder',
                'access_type' => 'basic',
            ],
        ]);

        DB::table('servers')->insert([
            [
                'id' => 1,
                'name' => 'Rancher',
                'address' => 'https://'.env('RANCHER_HOST'),
                'host' => 'https://'.env('RANCHER_HOST'),
                'api_key' => env('RANCHER_API_KEY') ?? 'api_key',
                'api_secret' => env('RANCHER_API_SECRET') ?? 'api_secret',
                'default_web_server' => 1,
                'internal_address' => 'localhost',
                'type' => 'web',
                'interface' => 'rancher',
                'settings' => '{"project_id":"'.env('RANCHER_PROJECT_ID').'"}',
                'ip' => '127.0.0.1',
                'status' => 'active',
            ],
        ]);

        // Add App Versions
        DB::table('app_versions')->insert([
            [
                'id' => 1,
                'application_id' => 1,
                'name' => '33.0.1',
                'status' => 'active',
                'roles' => '{}',
                'settings' => '{"helm_repo_name": "nextcloud", "chart_name": "nextcloud", "image_repo_name": "nextcloud", "image_registry": "docker.io", "chart_version": "9.0.4"}',
            ],
            [
                'id' => 2,
                'application_id' => 2,
                'name' => '6.8.2-debian-12-r5',
                'status' => 'active',
                'roles' => '{"order": [1, 2, 3, 4, 5, 6], "default_user_groups": [5], "default_admin_groups": [1]}',
                'settings' => '{"helm_repo_name": "bitnami", "image_repo_name": "bitnami/wordpress", "image_registry": "docker.io", "chart_name": "wordpress", "chart_version": "20.1.2"}',
            ],
        ]);

        DB::table('app_roles')->insert([
            [
                'id' => 1,
                'application_id' => 2,
                'label' => 'Administrator',
                'name' => 'Admin',
                'slug' => 'administrator',
                'description' => 'Administrator',
                'category' => 'Content',
                'access_type' => 'standard',
                'status' => 'enabled',
            ],
            [
                'id' => 3,
                'application_id' => 2,
                'label' => 'Editor  ',
                'name' => 'Editor',
                'slug' => 'editor',
                'description' => 'Editor',
                'category' => 'Content',
                'access_type' => 'standard',
                'status' => 'enabled',
            ],
            [
                'id' => 2,
                'application_id' => 2,
                'label' => 'Author',
                'name' => 'Author',
                'slug' => 'author',
                'description' => 'Author',
                'category' => 'Content',
                'access_type' => 'basic',
                'status' => 'enabled',
            ],
            [
                'id' => 4,
                'application_id' => 2,
                'label' => 'Contributor',
                'name' => 'Contributor',
                'slug' => 'contributor',
                'description' => 'Contributor',
                'category' => 'Content',
                'access_type' => 'basic',
                'status' => 'enabled',
            ],
            [
                'id' => 5,
                'application_id' => 2,
                'label' => 'Subscriber',
                'name' => 'Subscriber',
                'slug' => 'subscriber',
                'description' => 'Subscriber',
                'category' => 'Content',
                'access_type' => 'minimal',
                'status' => 'enabled',
            ],
            [
                'id' => 6,
                'application_id' => 1,
                'name' => 'Standard',
                'label' => 'Standard',
                'slug' => 'standard',
                'description' => 'Standard user',
                'category' => 'User',
                'access_type' => 'standard',
                'status' => 'enabled',
            ],
            [
                'id' => 7,
                'application_id' => 1,
                'name' => 'Volunteer',
                'label' => 'Volunteer',
                'slug' => 'basic',
                'description' => 'Volunteer',
                'category' => 'User',
                'access_type' => 'basic',
                'status' => 'enabled',
            ],
        ]);

        DB::table('plans')->insert([
            [
                'id' => 1,
                'display_order' => 1,
                'name' => 'Base Demo Plan',
                'type' => 'app',
                'description' => 'Basic base demo plan',
                'payment_enabled' => 0,
                'domain_enabled' => 1,
                'is_default' => 1,
                'org_type' => 'business',
                'features' => '[{"name":"Price","description":"Free"},{"name":"Storage","description":"?"}]',
                'app_plans' => '{"nextcloud": {"max": "1", "plans": [1]}, "wordpress": {"max": "1", "plans": [3]}}',
                'settings' => '{"base": {"price": null, "storage": null, "price_id": null}, "suborganizations": {"enabled": false}, "basic": {"max": null, "name": null, "price": null, "amount": null, "storage": null, "price_id": null}, "email": {"max": null, "price": null, "storage": null, "price_id": null}, "storage": {"max": null, "price": null, "amount": null, "price_id": null}, "standard": {"max": null, "price": null, "storage": null, "price_id": null}, "application": {"max": null, "price": null, "price_id": null}}',
            ],
            [
                'id' => 2,
                'display_order' => 2,
                'name' => 'Base Demo Paid Plan',
                'type' => 'package',
                'description' => 'Basic base demo paid plan',
                'payment_enabled' => 1,
                'domain_enabled' => 1,
                'is_default' => 0,
                'org_type' => 'business',
                'features' => '[{"name":"Price","description":"Free"},{"name":"Storage","description":"?"}]',
                'app_plans' => '{"nextcloud": {"max": "1", "plans": [1]}, "wordpress": {"max": "1", "plans": [3]}}',
                'settings' => '{"base": {"price": "5", "storage": null, "price_id": null}, "suborganizations": {"enabled": false}, "basic": {"max": "5", "name": null, "price": "5", "amount": "5", "storage": null, "price_id": null}, "email": {"max": null, "price": "5.00", "storage": "1", "price_id": null}, "storage": {"max": "5", "price": "5", "amount": "5", "price_id": null}, "standard": {"max": "5", "price": "5", "storage": null, "price_id": null}, "application": {"max": null, "price": "5.00", "price_id": null}}',
            ],
        ]);
        DB::table('app_plans')->insert([
            [
                'id' => 1,
                'application_id' => 1,
                'web_server_id' => 1,
                'display_order' => 1,
                'name' => 'Demo Plan',
                'description' => 'Basic demo plan',
                'features' => '[{"name":"Price","description":"Free"}]',
                'settings' => '{"base": {"max": null, "price": null, "storage": null, "price_id": null}, "basic": {"max": null, "name": null, "price": null, "amount": null, "storage": null, "price_id": null}, "storage": {"max": null, "price": null, "amount": "5", "price_id": null}, "features": {"deck": {"name": "deck", "price": null, "status": "optional", "settings": [], "price_id": null}, "spreed": {"name": "spreed", "price": null, "status": "optional", "settings": [], "price_id": null}, "calendar": {"name": "calendar", "price": null, "status": "optional", "settings": [], "price_id": null}, "contacts": {"name": "contacts", "price": null, "status": "optional", "settings": [], "price_id": null}, "onlyoffice": {"name": "onlyoffice", "price": null, "status": "disabled", "settings": {"onlyoffice_url": null, "onlyoffice_jwt_secret": null}, "price_id": null}, "php_settings": {"name": "php_settings", "price": null, "status": "disabled", "settings": {"PHP_MEMORY_LIMIT": null, "PHP_UPLOAD_LIMIT": null}, "price_id": null}, "fulltextsearch": {"name": "fulltextsearch", "price": null, "status": "disabled", "settings": {"elasticsearch_host": null}, "price_id": null}}, "standard": {"max": null, "price": null, "storage": null, "price_id": null}, "configurations": {"redis": {"master": {"persistence": {"enabled": false, "storageClass": "longhorn"}}, "enabled": true, "replica": {"persistence": {"enabled": false, "storageClass": "longhorn"}, "replicaCount": 1}}, "cronjob": false, "mariadb": {"auth": {"database": "nextcloud", "password": "changeme", "username": "nextcloud", "rootPassword": "changeme"}, "enabled": true, "primary": {"persistence": {"size": "8Gi", "enabled": false, "accessMode": "ReadWriteOnce", "storageClass": "", "existingClaim": ""}}, "architecture": "standalone", "existingSecret": ""}, "username": "admin", "hpa-enabled": false, "hpa-maxPods": 10, "hpa-minPods": 1, "rbac-enabled": true, "replicaCount": 1, "metrics-https": false, "ingress-enabled": false, "metrics-enabled": false, "hpa-cputhreshold": 60, "persistence-enabled": false, "resources-limits-cpu": "1250m", "startupProbe-enabled": true, "nextcloud-mail-domain": null, "nextcloud-mail-enabled": false, "persistence-accessMode": "ReadWriteOnce", "resources-requests-cpu": "500m", "nextcloud-strategy-type": "RollingUpdate", "resources-limits-memory": "1Gi", "externalDatabase-enabled": false, "nextcloud-mail-smtp-host": null, "nextcloud-mail-smtp-name": null, "nextcloud-mail-smtp-port": 465, "persistence-storageClass": null, "persistence-existingClaim": null, "resources-requests-memory": "500Mi", "nextcloud-mail-fromAddress": null, "nextcloud-mail-smtp-secure": "ssl", "startupProbe-periodSeconds": 10, "startupProbe-timeoutSeconds": 10, "nextcloud-mail-smtp-authtype": "PLAIN", "nextcloud-mail-smtp-password": "", "startupProbe-initialDelaySeconds": 10, "ingress-annotation-cluster_issuer": "letsencrypt-production"}}',
                'is_default' => 1,
                'web_server_id' => 1,
            ],
            [
                'id' => 2,
                'application_id' => 1,
                'web_server_id' => 1,
                'display_order' => 2,
                'name' => 'Paid Demo Plan 1',
                'description' => 'Paid demo plan',
                'features' => '[{"name":"Price","description":"Free"}]',
                'settings' => '{"base": {"max": 0, "price": 0, "storage": 0, "price_id": null}, "basic": {"max": 0, "name": null, "price": 0, "amount": 0, "storage": 0, "price_id": null}, "email": [], "storage": {"max": 0, "price": 0, "amount": 0, "price_id": null}, "standard": {"max": 0, "price": 0, "storage": 0, "price_id": null}, "application": [], "configurations": {"mariadb": {"auth": {"password": "password", "rootPassword": "root_password"}, "enabled": true, "primary": {"persistence": {"size": "4Gi", "enabled": false, "storageClass": ""}}}, "image-debug": false, "ingress-enabled": false, "wordpress-email": null, "image-pullPolicy": "IfNotPresent", "wordpress-plugins": null, "wordpress-lastname": "User", "wordpress-username": "admin", "persistence-enabled": false, "updateStrategy-type": "RollingUpdate", "wordpress-firstname": "Wordpress", "persistence-accessMode": "ReadWriteOnce", "persistence-accessModes": ["ReadWriteOnce"], "persistence-storageClass": null, "persistence-existingClaim": null, "updateStrategy-rollingUpdate": null, "ingress-annotation-cluster_issuer": "none", "customReadinessProbe-periodSeconds": 10, "customReadinessProbe-timeoutSeconds": 5, "customReadinessProbe-failureThreshold": 6, "customReadinessProbe-successThreshold": 1, "customReadinessProbe-initialDelaySeconds": 10, "ingress-ingress-annotation-traefik_middlewares": ""}}',
                'is_default' => 0,
                'web_server_id' => 1,
            ],
            [
                'id' => 3,
                'application_id' => 2,
                'web_server_id' => 1,
                'display_order' => 1,
                'name' => 'Demo Wordpress Plan',
                'description' => 'Basic wordpress demo plan',
                'features' => '[{"name":"Price","description":"Free"}]',
                'settings' => '{"base": {"max": null, "price": null, "storage": null, "price_id": null}, "basic": {"max": null, "name": null, "price": null, "amount": null, "storage": null, "price_id": null}, "storage": {"max": null, "price": null, "amount": null, "price_id": null}, "features": {"case": {"name": "case", "price": null, "status": "enabled", "settings": [], "price_id": null}, "event": {"name": "event", "price": null, "status": "enabled", "settings": [], "price_id": null}, "member": {"name": "member", "price": null, "status": "disabled", "settings": [], "price_id": null}, "pledge": {"name": "pledge", "price": null, "status": "disabled", "settings": [], "price_id": null}, "report": {"name": "report", "price": null, "status": "enabled", "settings": [], "price_id": null}, "mailing": {"name": "mailing", "price": null, "status": "enabled", "settings": [], "price_id": null}, "campaign": {"name": "campaign", "price": null, "status": "disabled", "settings": [], "price_id": null}, "contribution": {"name": "contribution", "price": null, "status": "enabled", "settings": [], "price_id": null}, "administrator": {"name": "administrator", "price": null, "status": "enabled", "settings": [], "price_id": null}}, "standard": {"max": null, "price": null, "storage": null, "price_id": null}}',
                'is_default' => 1,
            ],
            [
                'id' => 4,
                'application_id' => 2,
                'web_server_id' => 1,
                'display_order' => 2,
                'name' => 'Paid Wordpress Demo Plan',
                'description' => 'Paid wordpress demo plan',
                'features' => '[{"name":"Price","description":"Free"}]',
                'settings' => '{"base": {"max": null, "price": "5", "storage": "5", "price_id": null}, "basic": {"max": null, "name": "Volunteer", "price": "5", "amount": null, "storage": null, "price_id": null}, "storage": {"max": null, "price": "8", "amount": null, "price_id": null}, "features": {"case": {"name": "case", "price": null, "status": "enabled", "settings": [], "price_id": null}, "event": {"name": "event", "price": null, "status": "enabled", "settings": [], "price_id": null}, "member": {"name": "member", "price": null, "status": "disabled", "settings": [], "price_id": null}, "pledge": {"name": "pledge", "price": null, "status": "disabled", "settings": [], "price_id": null}, "report": {"name": "report", "price": null, "status": "enabled", "settings": [], "price_id": null}, "mailing": {"name": "mailing", "price": null, "status": "enabled", "settings": [], "price_id": null}, "campaign": {"name": "campaign", "price": null, "status": "disabled", "settings": [], "price_id": null}, "contribution": {"name": "contribution", "price": null, "status": "enabled", "settings": [], "price_id": null}, "administrator": {"name": "administrator", "price": null, "status": "enabled", "settings": [], "price_id": null}}, "standard": {"max": null, "price": "5", "storage": "5", "price_id": null}, "configurations": {"mariadb": {"auth": {"password": "password", "rootPassword": "root_password"}, "enabled": false, "primary": {"persistence": {"size": "4Gi", "enabled": false, "storageClass": ""}}}, "image-debug": false, "ingress-enabled": "default-middlewares@kubernetescrd", "wordpress-email": null, "image-pullPolicy": "IfNotPresent", "wordpress-plugins": null, "wordpress-lastname": "User", "wordpress-username": "admin", "persistence-enabled": false, "updateStrategy-type": "RollingUpdate", "wordpress-firstname": "Wordpress", "persistence-accessMode": "ReadWriteOnce", "persistence-accessModes": ["ReadWriteOnce"], "persistence-storageClass": "[\r\n    \"ReadWriteOnce\"\r\n]", "persistence-existingClaim": null, "updateStrategy-rollingUpdate": null, "updateStrategy-rollingupdate": null, "ingress-annotation-cluster_issuer": "letsencrypt-production", "customReadinessProbe-periodSeconds": 10, "customReadinessProbe-timeoutSeconds": 5, "customReadinessProbe-failureThreshold": 1, "customReadinessProbe-successThreshold": 1, "customReadinessProbe-initialDelaySeconds": 10, "ingress-ingress-annotation-traefik_middlewares": "default-middlewares@kubernetescrd"}}',
                'is_default' => 0,
            ],
        ]);

        DB::table('organizations')->insert([
            [
                'id' => 1,
                'base_domain_id' => 1,
                'plan_id' => 1,
                'slug' => 'demo',
                'name' => 'Demo',
                'description' => 'Demo Account',
                'email' => 'demoaccount@example.com',
                'phone_number' => '123-456-7890',
                'contact_first_name' => 'Demo',
                'contact_last_name' => 'User',
                'contact_email' => 'demouser@example.com',
                'contact_phone_number' => '098-765-4321',
                'street' => '123 Demo St',
                'zipcode' => '123 456',
                'city' => 'Demotown',
                'state' => 'AZ',
                'country' => 'US',
                'type' => 'superaccount',
                'secretpw' => Crypt::encryptString(Str::password(20, true, true, false)),
                'api_token' => Hash::make(Str::random(60)),
                'settings' => '{"step": 4}',
                'status' => 'active',
            ],
        ]);

        DB::table('org_domains')->insert([
            [
                'id' => 1,
                'organization_id' => 1,
                'app_instance_id' => 1,
                'name' => env('NEXTCLOUD_HOST'),
                'type' => 'connection',
                'source' => 'organization',
                'status' => 'active',
            ],
            [
                'id' => 2,
                'organization_id' => 1,
                'app_instance_id' => 2,
                'name' => env('WORDPRESS_HOST'),
                'type' => 'connection',
                'source' => 'organization',
                'status' => 'active',
            ],
        ]);

        DB::table('org_subdomains')->insert([
            [
                'id' => 1,
                'organization_id' => 1,
                'app_instance_id' => 1,
                'parent_domain_id' => 1,
                'host' => '@',
                'name' => env('NEXTCLOUD_HOST'),
                'type' => 'app',
                'status' => 'active',
            ],
            [
                'id' => 2,
                'organization_id' => 1,
                'app_instance_id' => 2,
                'parent_domain_id' => 2,
                'host' => '@',
                'name' => env('WORDPRESS_HOST'),
                'type' => 'app',
                'status' => 'active',
            ],
        ]);

        DB::table('org_servers')->insert([
            [
                'id' => 1,
                'organization_id' => 1,
                'server_id' => 1,
            ],
        ]);

        DB::table('server_settings')->insert([
            [
                'key' => 'installed',
                'value' => 1,
            ],
            [
                'key' => 'base_domain',
                'value' => 'local.dev',
            ],
            [
                'key' => 'support_user',
                'value' => 'admin',
            ],
            [
                'key' => 'invoice_vendor_name',
                'value' => 'Demo Company',
            ],
            [
                'key' => 'invoice_vendor_product',
                'value' => 'Demo Services',
            ],
            [
                'key' => 'invoice_vendor_street',
                'value' => 'Demo St.',
            ],
            [
                'key' => 'invoice_vendor_location',
                'value' => 'Demotown 123 456 DT US',
            ],
            [
                'key' => 'invoice_vendor_phone_number',
                'value' => '123-456-7890',
            ],
            [
                'key' => 'invoice_vendor_email',
                'value' => 'demo@example.com',
            ],
            [
                'key' => 'invoice_vendor_url',
                'value' => 'https://example.com',
            ],
            [
                'key' => 'installed_version',
                'value' => '0.1',
            ],
            [
                'key' => 'default_standard_price',
                'value' => '1',
            ],
        ]);

        DB::table('app_instances')->insert([
            [
                'id' => 1,
                'organization_id' => 1,
                'application_id' => 1,
                'version_id' => 1,
                'name' => 'nextcloud',
                'label' => 'Nextcloud',
                'primary_domain_id' => 1,
                'plan_id' => 1,
                'web_server_id' => 1,
                'api_password' => Crypt::encrypt(env('NEXTCLOUD_ADMIN_PASSWORD')),
                'status' => 'active',
            ],
            [
                'id' => 2,
                'organization_id' => 1,
                'application_id' => 2,
                'version_id' => 2,
                'name' => 'wordpress',
                'label' => 'Wordpress',
                'primary_domain_id' => 2,
                'plan_id' => 3,
                'web_server_id' => 1,
                'api_password' => Crypt::encrypt(env('WORDPRESS_ADMIN_PASSWORD')),
                'status' => 'active',
            ],
        ]);

        DB::table('users')->insert([
            [
                'id' => 1,
                'organization_id' => 1,
                'username' => 'demo',
                'name' => 'Demo User',
                'first_name' => 'Demo',
                'last_name' => 'User',
                'email' => 'demo@example.com',
                'email_verified_at' => now(),
                'password' => Hash::make('demouser'),
                'is_allowed' => true,
            ],
        ]);

        Organization::setOrganization(\App\Organization::find(1));
        AccountManager::accounts()->seeder('demo');
    }
}
