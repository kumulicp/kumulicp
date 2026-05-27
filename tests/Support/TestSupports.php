<?php

namespace Tests\Support;

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Jobs\Applications\AddLdapGroups;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Organization as LdapOrganization;
use App\Organization;
use App\Plan;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\Applications\DemoAppProfile;

class TestSupports
{
    public $demo_app;

    public $demo_app_1;

    public $demo_app_2;

    public $demo_app_unlimited;

    public $base_1;

    public $base_2;

    public $base_with_specific_app_plans;

    public $base_paid_plan;

    public $nextcloud_1;

    public $nextcloud_2;

    public $wordpress_1;

    public $wordpress_2;

    public function cleanLdap(): void
    {
        if (config('account_manager.driver') !== 'ldap') {
            return;
        }

        foreach (['demo', 'testing'] as $slug) {
            $org = LdapOrganization::find(Dn::create($slug));
            if ($org) {
                $org->delete($recursive = true);
            }
        }
    }

    public function seed(): void
    {
        DB::table('server_settings')->insert([
            ['key' => 'installed', 'value' => 1],
            ['key' => 'base_domain', 'value' => 'local.dev'],
            ['key' => 'support_user', 'value' => 'admin'],
            ['key' => 'invoice_vendor_name', 'value' => 'Demo Company'],
            ['key' => 'invoice_vendor_product', 'value' => 'Demo Services'],
            ['key' => 'invoice_vendor_street', 'value' => 'Demo St.'],
            ['key' => 'invoice_vendor_location', 'value' => 'Demotown 123 456 DT US'],
            ['key' => 'invoice_vendor_phone_number', 'value' => '123-456-7890'],
            ['key' => 'invoice_vendor_email', 'value' => 'demo@example.com'],
            ['key' => 'invoice_vendor_url', 'value' => 'https://example.com'],
            ['key' => 'installed_version', 'value' => '0.1'],
            ['key' => 'default_standard_price', 'value' => '1'],
        ]);

        DB::table('servers')->insert([[
            'id' => 1,
            'app_instance_id' => 0,
            'name' => 'Rancher',
            'address' => 'https://rancher.local',
            'host' => 'https://rancher.local',
            'api_key' => 'api_key',
            'api_secret' => 'api_secret',
            'default_web_server' => 1,
            'default_email_server' => 0,
            'default_database_server' => 0,
            'internal_address' => 'localhost',
            'type' => 'web',
            'interface' => 'rancher',
            'settings' => '{"project_id":"test"}',
            'ip' => '127.0.0.1',
            'status' => 'active',
        ]]);

        $this->base_1 = Plan::factory()->create([
            'payment_enabled' => false,
            'type' => 'app',
            'is_default' => true,
            'app_plans' => ['demo_app' => ['max' => 1, 'plans' => 'enabled']],
            'settings' => [
                'base' => ['price' => 1, 'storage' => 1, 'price_id' => 'stripe_base', 'minimal_label' => 'minimal'],
                'basic' => ['max' => 1, 'name' => 'Base 1', 'price' => 1, 'amount' => 1, 'storage' => 1, 'price_id' => 'stripe_basic'],
                'email' => ['max' => 1, 'price' => 1, 'storage' => 1, 'price_id' => 'stripe_email'],
                'storage' => ['max' => 1, 'price' => 1, 'amount' => 1, 'price_id' => 'stripe_storage'],
                'standard' => ['max' => 10, 'price' => 1, 'storage' => 1, 'price_id' => 'stripe_standard'],
                'application' => ['max' => 1, 'price' => 1, 'price_id' => 'stripe_application'],
                'domains' => ['connect' => true, 'register' => false, 'transfer' => false],
            ],
        ]);

        DB::table('organizations')->insert([[
            'id' => 1,
            'plan_id' => $this->base_1->id,
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
        ]]);

        DB::table('org_servers')->insert([['id' => 1, 'organization_id' => 1, 'server_id' => 1]]);

        DB::table('users')->insert([[
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
        ]]);

        OrganizationFacade::setOrganization(\App\Organization::find(1));
        AccountManager::accounts()->seeder('demo');
        app()->forgetInstance('settings');
    }

    public function addUsers()
    {
        $user = AccountManager::users()->add([
            'username' => 'testing1',
            'first_name' => 'test',
            'last_name' => 'user1',
            'name' => 'test user1',
            'email' => 'testing1@example.com',
            'password' => 'password',
            'phone_number' => '1234567890',
        ]);

        $user = AccountManager::users()->add([
            'username' => 'testing2',
            'first_name' => 'test',
            'last_name' => 'user2',
            'name' => 'test user2',
            'email' => 'testing2@example.com',
            'password' => 'password',
            'phone_number' => '1234567890',
        ]);
    }

    public function setSubscription(Organization $organization, Plan $base_plan, ?AppPlan $app_plan = null, ?AppInstance $app = null)
    {
        $subscription = (new SubscriptionService($organization))->all()->updateBase($base_plan);
        if ($app_plan && $app) {
            $subscription->updateApp($app_plan, $app);
        }

        $task = Action::execute(new SubscriptionUpdate($organization, $subscription));
        Artisan::call('schedule:run');
        Artisan::call('schedule:run');
        $task->refresh();

        Cache::flush();

        Subscription::refresh();
    }

    public function populate(): void
    {
        $this->activateDemoApp();
        $this->createDemoAppPlans();
        $this->createBase2Plan();
        $this->createNextcloudPlans();
        $this->createWordpressPlans();
        $this->createBaseWithSpecificPlans();
    }

    public function createBase2Plan(): void
    {
        $this->base_2 = Plan::factory()->create([
            'app_plans' => ['demo_app' => ['max' => 1, 'plans' => 'enabled']],
            'type' => 'package',
            'org_type' => 'nonprofit',
            'payment_enabled' => true,
            'domain_enabled' => true,
            'domain_max' => 2,
            'email_enabled' => true,
            'settings' => [
                'base' => ['price' => 2, 'storage' => 2, 'price_id' => null, 'minimal_label' => 'minimal'],
                'basic' => ['max' => 2, 'name' => 'Basic 2', 'price' => 2, 'amount' => 1, 'storage' => 2, 'price_id' => null],
                'email' => ['max' => 2, 'price' => 2, 'storage' => 2, 'price_id' => null],
                'storage' => ['max' => 2, 'price' => 2, 'amount' => 2, 'price_id' => null],
                'standard' => ['max' => 2, 'price' => 2, 'storage' => 2, 'price_id' => null],
                'application' => ['max' => 2, 'price' => 2, 'price_id' => null],
                'domains' => ['connect' => true, 'register' => false, 'transfer' => false],
            ],
        ]);
    }

    public function createDemoAppPlans(): void
    {
        $demo_app = Application::where('slug', 'demo_app')->firstOrFail();

        $this->demo_app_1 = AppPlan::factory()->create([
            'name' => 'demo_app_1',
            'payment_enabled' => false,
            'application_id' => $demo_app->id,
            'settings' => [
                'base' => ['price' => 1, 'storage' => 1, 'price_id' => 'stripe_base'],
                'basic' => ['max' => 1, 'name' => 'Basic 1', 'price' => 1, 'amount' => 1, 'storage' => 0.5, 'price_id' => 'stripe_basic'],
                'storage' => ['max' => 1, 'price' => 1, 'amount' => 1, 'price_id' => 'stripe_storage'],
                'standard' => ['max' => 1, 'price' => 1, 'storage' => 1, 'price_id' => 'stripe_standard'],
            ],
        ]);

        $this->demo_app_2 = AppPlan::factory()->create([
            'name' => 'demo_app_2',
            'payment_enabled' => true,
            'application_id' => $demo_app->id,
            'settings' => [
                'base' => ['price' => 2, 'storage' => 2, 'price_id' => 'stripe_base'],
                'basic' => ['max' => 2, 'name' => 'Basic 2', 'price' => 2, 'amount' => 2, 'storage' => 1, 'price_id' => 'stripe_basic'],
                'storage' => ['max' => 2, 'price' => 2, 'amount' => 2, 'price_id' => 'stripe_storage'],
                'standard' => ['max' => 2, 'price' => 2, 'storage' => 2, 'price_id' => 'stripe_standard'],
            ],
        ]);

        $this->demo_app_unlimited = AppPlan::factory()->create([
            'payment_enabled' => true,
            'application_id' => $demo_app->id,
            'settings' => [
                'base' => ['price' => 1, 'storage' => 1, 'price_id' => null],
                'basic' => ['max' => null, 'name' => 'Basic Unlimited', 'price' => 2, 'amount' => 2, 'storage' => 1, 'price_id' => null],
                'storage' => ['max' => null, 'price' => 1, 'amount' => 1, 'price_id' => null],
                'standard' => ['max' => null, 'price' => 1, 'storage' => 1, 'price_id' => null],
            ],
        ]);
    }

    public function createBaseWithSpecificPlans(): void
    {
        $demo_app = Application::where('slug', 'demo_app')->firstOrFail();

        $this->base_with_specific_app_plans = Plan::factory()->create([
            'app_plans' => [
                'demo_app' => ['max' => '1', 'plans' => $demo_app->plans()->first()->id],
            ],
            'settings' => [
                'base' => ['price' => null, 'storage' => null, 'price_id' => null],
                'basic' => ['max' => null, 'name' => null, 'price' => null, 'amount' => null, 'storage' => null, 'price_id' => null],
                'email' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
                'storage' => ['max' => null, 'price' => null, 'amount' => null, 'price_id' => null],
                'standard' => ['max' => 2, 'price' => null, 'storage' => null, 'price_id' => null],
                'application' => ['max' => null, 'price' => null, 'price_id' => null],
                'domains' => ['connect' => false, 'register' => false, 'transfer' => false],
            ],
        ]);
    }

    public function createNextcloudPlans(): void
    {
        $nextcloud = Application::firstOrCreate(
            ['slug' => 'nextcloud'],
            ['name' => 'Nextcloud', 'short_description' => 'Nextcloud', 'description' => 'Nextcloud', 'domain_option' => 'base', 'parent_app_id' => 0, 'enabled' => 1, 'category' => 'File Sharing & Collaboration', 'access_type' => 'standard']
        );

        $app_plan = new AppPlan;
        $app_plan->name = 'test';
        $app_plan->application_id = $nextcloud->id;
        $app_plan->settings = [
            'base' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
            'basic' => ['max' => 100, 'name' => 'Basic Nextcloud', 'price' => null, 'amount' => null, 'storage' => 0.5, 'price_id' => null],
            'storage' => ['max' => 3, 'price' => null, 'amount' => 5, 'price_id' => null],
            'standard' => ['max' => 100, 'price' => null, 'storage' => 5, 'price_id' => null],
        ];
        $app_plan->save();
        $this->nextcloud_1 = $app_plan;

        $app_plan = new AppPlan;
        $app_plan->name = 'test2';
        $app_plan->application_id = $nextcloud->id;
        $app_plan->settings = [
            'base' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
            'basic' => ['max' => 100, 'name' => 'Basic Nextcloud', 'price' => null, 'amount' => null, 'storage' => 1.5, 'price_id' => null],
            'storage' => ['max' => 3, 'price' => null, 'amount' => 5, 'price_id' => null],
            'standard' => ['max' => 100, 'price' => null, 'storage' => 10, 'price_id' => null],
        ];
        $app_plan->save();
        $this->nextcloud_2 = $app_plan;
    }

    public function createWordpressPlans(): void
    {
        $wordpress = Application::firstOrCreate(
            ['slug' => 'wordpress'],
            ['name' => 'Wordpress', 'short_description' => 'Wordpress', 'description' => 'Wordpress', 'domain_option' => 'base', 'parent_app_id' => 0, 'enabled' => 1, 'category' => 'Website Builder', 'access_type' => 'basic']
        );

        $app_plan = new AppPlan;
        $app_plan->name = 'test1';
        $app_plan->application_id = $wordpress->id;
        $app_plan->settings = [
            'base' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic Wordpress', 'price' => null, 'amount' => null, 'storage' => 1.5, 'price_id' => null],
            'storage' => ['max' => 3, 'price' => null, 'amount' => 5, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => null, 'storage' => 10, 'price_id' => null],
        ];
        $app_plan->save();
        $this->wordpress_1 = $app_plan;

        $app_plan = new AppPlan;
        $app_plan->name = 'test2';
        $app_plan->application_id = $wordpress->id;
        $app_plan->settings = [
            'base' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
            'basic' => ['max' => 2, 'name' => 'Basic Wordpress', 'price' => null, 'amount' => null, 'storage' => 1.5, 'price_id' => null],
            'storage' => ['max' => 3, 'price' => null, 'amount' => 5, 'price_id' => null],
            'standard' => ['max' => 2, 'price' => null, 'storage' => 10, 'price_id' => null],
        ];
        $app_plan->save();
        $this->wordpress_2 = $app_plan;
    }

    /**
     * Register DemoApp and return the Application + a ready AppPlan.
     * Does NOT create an AppInstance — use this before running ApplicationActivate.
     *
     * @return array{app: \App\Application, plan: AppPlan}
     */
    public function prepareDemoApp(?int $webServerId = 1): array
    {
        AppFacade::register(new DemoAppProfile);

        $app = AppFacade::initialize('demo_app');

        AppFacade::roles($app);

        $roles = [];
        foreach ($app->roles()->get() as $role) {
            $roles[] = $role->id;
        }

        $version = AppVersion::factory()->create([
            'application_id' => $app->id,
        ]);
        $version->roles = ['order' => $roles];
        $version->save();

        $plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'web_server_id' => $webServerId,
            'settings' => [
                'base' => ['max' => 0, 'price' => 0, 'storage' => 0, 'price_id' => null],
                'basic' => ['max' => 0, 'name' => null, 'price' => 0, 'amount' => 0, 'storage' => 0, 'price_id' => null],
                'storage' => ['max' => 0, 'price' => 0, 'amount' => 0, 'price_id' => null],
                'standard' => ['max' => 0, 'price' => 0, 'storage' => 0, 'price_id' => null],
                'configurations' => [],
            ],
        ]);

        return ['app' => $app, 'plan' => $plan];
    }

    public function activateDemoApp()
    {
        AppFacade::register(new DemoAppProfile);

        $app = AppFacade::initialize('demo_app');

        $app_plan = AppPlan::factory()->create([
            'application_id' => $app->id,
        ]);

        AppFacade::roles($app); // Automatically adds roles to database

        $roles = [];
        foreach ($app->roles()->get() as $role) {
            $roles[] = $role->id;
        }

        $version = AppVersion::factory()->create([
            'application_id' => $app->id,
        ]);
        $version->roles = ['order' => $roles];
        $version->save();

        $demo_app = AppFacade::get('demo_app');

        $app_instance = AppFacade::activate(Organization::find(1), $app, $version, $app_plan);
        AddLdapGroups::dispatch($app_instance->get());
        $user = AccountManager::users()->find('demo');
        $permissions = $user->permissions()->updateAppRoles($app_instance->get(), []);
        $app_instance->status = 'active';
        $app_instance->save();

        $this->demo_app = Application::where('slug', 'demo_app')->first();
    }

    public function disableApps(): void
    {
        if ($app_1 = AppInstance::find(1)) {
            $app_1->organization_id = 10;
            $app_1->save();
        }
        if ($app_2 = AppInstance::find(2)) {
            $app_2->organization_id = 10;
            $app_2->save();
        }
    }
}
