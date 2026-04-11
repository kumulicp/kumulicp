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
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
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

    public function seed()
    {
        if (env('ACCOUNTMANAGER_DRIVER') === 'ldap') {
            $org = LdapOrganization::find(Dn::create('demo'));
            if ($org) {
                $org->delete($recursive = true);
            }
            $org = LdapOrganization::find(Dn::create('testing'));
            if ($org) {
                $org->delete($recursive = true);
            }
        }
        Artisan::call('db:seed DemoSeeder');
        Role::create(['name' => 'control_panel_admin']);
        Role::create(['name' => 'organization_admin']);
        Role::create(['name' => 'billing_manager']);
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

    public function populate()
    {
        $this->activateDemoApp();
        $this->demo_app = Application::where('slug', 'demo_app')->first();
        $this->demo_app_1 = AppPlan::factory()->create([
            'name' => 'demo_app_1',
            'payment_enabled' => false,
            'application_id' => $this->demo_app->id,
            'settings' => [
                'base' => [
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => 'stripe_base',
                ],
                'basic' => [
                    'max' => 1,
                    'name' => 'Basic 1',
                    'price' => 1,
                    'amount' => 1,
                    'storage' => 0.5,
                    'price_id' => 'stripe_basic',
                ],
                'storage' => [
                    'max' => 1,
                    'price' => 1,
                    'amount' => 1,
                    'price_id' => 'stripe_storage',
                ],
                'standard' => [
                    'max' => 1,
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => 'stripe_standard',
                ],
            ],
        ]);

        $this->demo_app_2 = AppPlan::factory()->create([
            'name' => 'demo_app_2',
            'payment_enabled' => true,
            'application_id' => $this->demo_app->id,
            'settings' => [
                'base' => [
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => 'stripe_base',
                ],
                'basic' => [
                    'max' => 2,
                    'name' => 'Basic 2',
                    'price' => 2,
                    'amount' => 2,
                    'storage' => 1,
                    'price_id' => 'stripe_basic',
                ],
                'storage' => [
                    'max' => 2,
                    'price' => 2,
                    'amount' => 2,
                    'price_id' => 'stripe_storage',
                ],
                'standard' => [
                    'max' => 2,
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => 'stripe_standard',
                ],
            ],
        ]);
        $this->demo_app_unlimited = AppPlan::factory()->create([
            'payment_enabled' => true,
            'application_id' => $this->demo_app->id,
            'settings' => [
                'base' => [
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => null,
                ],
                'basic' => [
                    'max' => null,
                    'name' => 'Basic Unlimited',
                    'price' => 2,
                    'amount' => 2,
                    'storage' => 1,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => null,
                    'price' => 1,
                    'amount' => 1,
                    'price_id' => null,
                ],
                'standard' => [
                    'max' => null,
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => null,
                ],
            ],
        ]);

        $this->base_with_specific_app_plans = Plan::factory()->create([
            'app_plans' => [
                'demo_app' => ['max' => '1', 'plans' => $this->demo_app->plans()->first()->id],
            ],
            'settings' => [
                'base' => ['price' => null, 'storage' => null, 'price_id' => null],
                'basic' => [
                    'max' => null,
                    'name' => null,
                    'price' => null,
                    'amount' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'email' => [
                    'max' => null,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => null,
                    'price' => null,
                    'amount' => null,
                    'price_id' => null,
                ],
                'standard' => [
                    'max' => 2,
                    'price' => null,
                    'storage' => null,
                    'price_id' => null,
                ],
                'application' => ['max' => null, 'price' => null, 'price_id' => null],
                'domains' => [
                    'connect' => false,
                    'register' => false,
                    'transfer' => false,
                ],
            ],
        ]);

        $this->base_1 = Plan::factory()->create([
            'payment_enabled' => false,
            'type' => 'app',
            'app_plans' => [
                'nextcloud' => ['max' => 1, 'plans' => 'enabled'],
                'demo_app' => ['max' => 1, 'plans' => 'enabled'],
            ],
            'settings' => [
                'base' => [
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => 'stripe_base',
                    'minimal_label' => 'minimal',
                ],
                'basic' => [
                    'max' => 1,
                    'name' => 'Base 1',
                    'price' => 1,
                    'amount' => 1,
                    'storage' => 1,
                    'price_id' => 'stripe_basic',
                ],
                'email' => [
                    'max' => 1,
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => 'stripe_email',
                ],
                'storage' => [
                    'max' => 1,
                    'price' => 1,
                    'amount' => 1,
                    'price_id' => 'stripe_storage',
                ],
                'standard' => [
                    'max' => 1,
                    'price' => 1,
                    'storage' => 1,
                    'price_id' => 'stripe_standard',
                ],
                'application' => [
                    'max' => 1,
                    'price' => 1,
                    'price_id' => 'stripe_application',
                ],
                'domains' => [
                    'connect' => false,
                    'register' => false,
                    'transfer' => false,
                ],
            ],
        ]);
        $this->base_2 = Plan::factory()->create([
            'app_plans' => [
                'demo_app' => ['max' => 1, 'plans' => 'enabled'],
            ],
            'type' => 'package',
            'org_type' => 'nonprofit',
            'payment_enabled' => true,
            'domain_enabled' => true,
            'domain_max' => 2,
            'email_enabled' => true,
            'settings' => [
                'base' => [
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => null,
                    'minimal_label' => 'minimal',
                ],
                'basic' => [
                    'max' => 2,
                    'name' => 'Basic 2',
                    'price' => 2,
                    'amount' => 1,
                    'storage' => 2,
                    'price_id' => null,
                ],
                'email' => [
                    'max' => 2,
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => 2,
                    'price' => 2,
                    'amount' => 2,
                    'price_id' => null,
                ],
                'standard' => [
                    'max' => 2,
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => null,
                ],
                'application' => [
                    'max' => 2,
                    'price' => 2,
                    'price_id' => null,
                ],
                'domains' => [
                    'connect' => false,
                    'register' => false,
                    'transfer' => false,
                ],
            ],
        ]);

        $this->base_unlimited = Plan::factory()->create([
            'payment_enabled' => true,
            'domain_enabled' => true,
            'app_plans' => [
                'nextcloud' => ['max' => 1, 'plans' => 'enabled'],
                'demo_app' => ['max' => 1, 'plans' => 'enabled'],
            ],
            'settings' => [
                'base' => [
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => null,
                    'minimal_label' => 'minimal',
                ],
                'basic' => [
                    'max' => null,
                    'name' => 'Basic Unlimited',
                    'price' => 2,
                    'amount' => 2,
                    'storage' => 2,
                    'price_id' => null,
                ],
                'email' => [
                    'max' => null,
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => null,
                ],
                'storage' => [
                    'max' => null,
                    'price' => 2,
                    'amount' => 2,
                    'price_id' => null,
                ],
                'standard' => [
                    'max' => null,
                    'price' => 2,
                    'storage' => 2,
                    'price_id' => null,
                ],
                'application' => [
                    'max' => null,
                    'price' => 2,
                    'price_id' => null,
                ],
                'domains' => [
                    'connect' => false,
                    'register' => false,
                    'transfer' => false,
                ],
            ],
        ]);

        $app_plan = new AppPlan;
        $app_plan->name = 'test';
        $app_plan->application_id = 1;
        $app_plan->settings = [
            'base' => [
                'max' => null,
                'price' => null,
                'storage' => null,
                'price_id' => null,
            ],
            'basic' => [
                'max' => 100,
                'name' => 'Basic Nextcloud',
                'price' => null,
                'amount' => null,
                'storage' => 0.5,
                'price_id' => null,
            ],
            'storage' => [
                'max' => 3,
                'price' => null,
                'amount' => 5,
                'price_id' => null,
            ],
            'features' => [
                'deck' => [
                    'name' => 'deck',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'spreed' => [
                    'name' => 'spreed',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'calendar' => [
                    'name' => 'calendar',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'contacts' => [
                    'name' => 'contacts',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'php_settings' => [
                    'name' => 'php_settings',
                    'price' => null,
                    'status' => 'disabled',
                    'settings' => [
                        'PHP_MEMORY_LIMIT' => null,
                        'PHP_UPLOAD_LIMIT' => null,
                    ],
                    'price_id' => null,
                ],
            ],
            'standard' => [
                'max' => 100,
                'price' => null,
                'storage' => 5,
                'price_id' => null,
            ],
            'configurations' => [
                'redis' => [
                    'master' => [
                        'persistence' => [
                            'enabled' => false,
                            'storageClass' => 'longhorn',
                        ],
                    ],
                    'enabled' => true,
                    'replica' => [
                        'persistence' => [
                            'enabled' => false,
                            'storageClass' => 'longhorn',
                        ],
                        'replicaCount' => 1,
                    ],
                ],
                'cronjob' => false,
                'mariadb' => [
                    'auth' => [
                        'database' => 'nextcloud',
                        'password' => 'changeme',
                        'username' => 'nextcloud',
                    ],
                    'enabled' => true,
                    'primary' => [
                        'persistence' => [
                            'size' => '8Gi',
                            'enabled' => false,
                            'accessMode' => 'ReadWriteOnce',
                            'storageClass' => '',
                            'existingClaim' => '',
                        ],
                    ],
                    'architecture' => 'standalone',
                    'existingSecret' => '',
                ],
                'username' => 'support',
                'hpa-enabled' => false,
                'hpa-maxPods' => 10,
                'hpa-minPods' => 1,
                'rbac-enabled' => true,
                'replicaCount' => 1,
                'metrics-https' => false,
                'ingress-enabled' => false,
                'metrics-enabled' => false,
                'hpa-cputhreshold' => 60,
                'persistence-enabled' => false,
                'resources-limits-cpu' => '1250m',
                'startupProbe-enabled' => true,
                'nextcloud-mail-domain' => null,
                'nextcloud-mail-enabled' => false,
                'persistence-accessMode' => 'ReadWriteOnce',
                'resources-requests-cpu' => '500m',
                'nextcloud-strategy-type' => 'RollingUpdate',
                'resources-limits-memory' => '1Gi',
                'externalDatabase-enabled' => false,
                'nextcloud-mail-smtp-host' => null,
                'nextcloud-mail-smtp-name' => null,
                'nextcloud-mail-smtp-port' => 465,
                'persistence-storageClass' => null,
                'persistence-existingClaim' => null,
                'resources-requests-memory' => '500Mi',
                'nextcloud-mail-fromAddress' => null,
                'nextcloud-mail-smtp-secure' => 'ssl',
                'startupProbe-periodSeconds' => 10,
                'startupProbe-timeoutSeconds' => 10,
                'nextcloud-mail-smtp-authtype' => 'PLAIN',
                'nextcloud-mail-smtp-password' => '',
                'startupProbe-initialDelaySeconds' => 10,
                'ingress-annotation-cluster_issuer' => 'letsencrypt-production',
            ],
        ];
        $app_plan->save();
        $this->nextcloud_1 = $app_plan;

        $app_plan = new AppPlan;
        $app_plan->name = 'test2';
        $app_plan->application_id = 1;
        $app_plan->settings = [
            'base' => [
                'max' => null,
                'price' => null,
                'storage' => null,
                'price_id' => null,
            ],
            'basic' => [
                'max' => 100,
                'name' => 'Basic Nextcloud',
                'price' => null,
                'amount' => null,
                'storage' => 1.5,
                'price_id' => null,
            ],
            'storage' => [
                'max' => 3,
                'price' => null,
                'amount' => 5,
                'price_id' => null,
            ],
            'features' => [
                'deck' => [
                    'name' => 'deck',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'spreed' => [
                    'name' => 'spreed',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'calendar' => [
                    'name' => 'calendar',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'contacts' => [
                    'name' => 'contacts',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'php_settings' => [
                    'name' => 'php_settings',
                    'price' => null,
                    'status' => 'disabled',
                    'settings' => [
                        'PHP_MEMORY_LIMIT' => null,
                        'PHP_UPLOAD_LIMIT' => null,
                    ],
                    'price_id' => null,
                ],
            ],
            'standard' => [
                'max' => 100,
                'price' => null,
                'storage' => 10,
                'price_id' => null,
            ],
            'configurations' => [
                'redis' => [
                    'master' => [
                        'persistence' => [
                            'enabled' => false,
                            'storageClass' => 'longhorn',
                        ],
                    ],
                    'enabled' => true,
                    'replica' => [
                        'persistence' => [
                            'enabled' => false,
                            'storageClass' => 'longhorn',
                        ],
                        'replicaCount' => 1,
                    ],
                ],
                'cronjob' => false,
                'mariadb' => [
                    'auth' => [
                        'database' => 'nextcloud',
                        'password' => 'changeme',
                        'username' => 'nextcloud',
                    ],
                    'enabled' => true,
                    'primary' => [
                        'persistence' => [
                            'size' => '8Gi',
                            'enabled' => false,
                            'accessMode' => 'ReadWriteOnce',
                            'storageClass' => '',
                            'existingClaim' => '',
                        ],
                    ],
                    'architecture' => 'standalone',
                    'existingSecret' => '',
                ],
                'username' => 'support',
                'hpa-enabled' => false,
                'hpa-maxPods' => 10,
                'hpa-minPods' => 1,
                'rbac-enabled' => true,
                'replicaCount' => 1,
                'metrics-https' => false,
                'ingress-enabled' => false,
                'metrics-enabled' => false,
                'hpa-cputhreshold' => 60,
                'persistence-enabled' => false,
                'resources-limits-cpu' => '1250m',
                'startupProbe-enabled' => true,
                'nextcloud-mail-domain' => null,
                'nextcloud-mail-enabled' => false,
                'persistence-accessMode' => 'ReadWriteOnce',
                'resources-requests-cpu' => '500m',
                'nextcloud-strategy-type' => 'RollingUpdate',
                'resources-limits-memory' => '1Gi',
                'externalDatabase-enabled' => false,
                'nextcloud-mail-smtp-host' => null,
                'nextcloud-mail-smtp-name' => null,
                'nextcloud-mail-smtp-port' => 465,
                'persistence-storageClass' => null,
                'persistence-existingClaim' => null,
                'resources-requests-memory' => '500Mi',
                'nextcloud-mail-fromAddress' => null,
                'nextcloud-mail-smtp-secure' => 'ssl',
                'startupProbe-periodSeconds' => 10,
                'startupProbe-timeoutSeconds' => 10,
                'nextcloud-mail-smtp-authtype' => 'PLAIN',
                'nextcloud-mail-smtp-password' => '',
                'startupProbe-initialDelaySeconds' => 10,
                'ingress-annotation-cluster_issuer' => 'letsencrypt-production',
            ],
        ];
        $app_plan->save();
        $this->nextcloud_2 = $app_plan;

        $app_plan = new AppPlan;
        $app_plan->name = 'test1';
        $app_plan->application_id = 2;
        $app_plan->settings = [
            'base' => [
                'max' => null,
                'price' => null,
                'storage' => null,
                'price_id' => null,
            ],
            'basic' => [
                'max' => 1,
                'name' => 'Basic Wordpress',
                'price' => null,
                'amount' => null,
                'storage' => 1.5,
                'price_id' => null,
            ],
            'storage' => [
                'max' => 3,
                'price' => null,
                'amount' => 5,
                'price_id' => null,
            ],
            'features' => [
            ],
            'standard' => [
                'max' => 1,
                'price' => null,
                'storage' => 10,
                'price_id' => null,
            ],
            'configurations' => [
            ],
        ];
        $app_plan->save();
        $this->wordpress_1 = $app_plan;

        $app_plan = new AppPlan;
        $app_plan->name = 'test2';
        $app_plan->application_id = 2;
        $app_plan->settings = [
            'base' => [
                'max' => null,
                'price' => null,
                'storage' => null,
                'price_id' => null,
            ],
            'basic' => [
                'max' => 2,
                'name' => 'Basic Wordpress',
                'price' => null,
                'amount' => null,
                'storage' => 1.5,
                'price_id' => null,
            ],
            'storage' => [
                'max' => 3,
                'price' => null,
                'amount' => 5,
                'price_id' => null,
            ],
            'features' => [
            ],
            'standard' => [
                'max' => 2,
                'price' => null,
                'storage' => 10,
                'price_id' => null,
            ],
            'configurations' => [
            ],
        ];
        $app_plan->save();
        $this->wordpress_2 = $app_plan;
    }

    public function activateDemoApp()
    {
        AppFacade::register(new DemoAppProfile);

        $app = AppFacade::initialize('demo_app');

        $app_plan = AppPlan::factory()->create();

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
    }

    public function disableApps()
    {
        $app_1 = AppInstance::find(1);
        $app_1->organization_id = 10;
        $app_1->save();

        $app_2 = AppInstance::find(2);
        $app_2->organization_id = 10;
        $app_2->save();
    }
}
