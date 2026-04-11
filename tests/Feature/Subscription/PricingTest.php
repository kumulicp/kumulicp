<?php

namespace Tests\Feature\Subscription;

use App\Enums\PlanEntity;
use App\Server;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSupports;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_pricing_change()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);
        $this->followingRedirects();
        $support->base_1->payment_enabled = true;
        $support->base_1->save();

        $demo_app = $support->demo_app->instances()->first();
        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_1, $demo_app);

        Subscription::refresh();
        $base_pricing = Subscription::base();

        $this->assertEquals(1.00, $base_pricing->optionStats('base')['total_price']);
        $this->assertEquals(1.00, $base_pricing->optionStats('standard')['total_price']);
        $this->assertEquals(0.00, $base_pricing->optionStats('basic')['total_price']);
        $this->assertEquals(0.00, $base_pricing->optionStats('storage')['total_price']);
    }

    public function test_adding_standard_users_pricing()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);
        // $this->followingRedirects();

        $demo_app = $support->demo_app->instances()->first();

        $support->setSubscription($user->organization, $support->base_2, $support->demo_app_2, $demo_app);
        $subscription = Subscription::all();

        $base_pricing = Subscription::base();
        $this->assertEquals(2.00, $base_pricing->optionStats('standard')['total_price']);

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertEquals(4.00, $base_pricing->optionStats('standard')['total_price']);
    }

    public function test_adding_basic_users_pricing()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);
        $this->followingRedirects();

        $demo_app = $support->demo_app->instances()->first();
        $support->setSubscription($user->organization, $support->base_2, $support->demo_app_2, $demo_app);

        $subscription = Subscription::all();
        $base_pricing = $subscription->base();
        $this->assertEquals(0.00, $base_pricing->optionStats('basic')['total_price']);

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $this->assertEquals(2.00, $base_pricing->optionStats('basic')['total_price']);

        $this->followingRedirects();

        $permission2 = $this->post('/users/testing2/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertEquals(4.00, $base_pricing->optionStats('basic')['total_price']);

        $response3 = $this->post('/users', [
            'username' => 'testing3',
            'first_name' => 'test',
            'last_name' => 'user3',
            'personal_email' => 'test3@example.com',
        ]);
        sleep(1);
        $permission3 = $this->post('/users/testing3/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertEquals(4.00, $base_pricing->optionStats('basic')['total_price']);
    }

    public function test_adding_additional_storage_pricing()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        Http::fake([
            'https://demo-nextcloud.example.com:443/ocs/v1.php/cloud/users/testing1' => ['hey' => 'there'],
        ]);

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $demo_app = $support->demo_app->instances()->first();
        Organization::setOrganization($user->organization);
        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_2, $demo_app);

        $this->actingAs($user);
        $this->followingRedirects();
        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                1 => 'none',
                2 => ['none'],
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $edit1 = $this->put('/users/testing1', [
            'first_name' => 'test',
            'last_name' => 'user1',
            'personal_email' => 'test1@example.com',
            'organization' => $user->organization->id,
            'additional_storage' => [
                $demo_app->id => 1,
            ],
        ]);

        $app_pricing = Subscription::app_instance($demo_app);
        $this->assertEquals(2.00, $app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price']);

        $this->assertEquals(4, AccountManager::users()->find('testing1')->appStorage($demo_app));
        $permission1 = $this->post('/users/testing2/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $edit2 = $this->put('/users/testing2', [
            'first_name' => 'test',
            'last_name' => 'user2',
            'personal_email' => 'test2@example.com',
            'organization' => $user->organization->id,
            'additional_storage' => [
                $demo_app->id => 1,
            ],
        ]);

        // Should now equal 5
        $this->assertEquals(4, AccountManager::users()->find('testing2')->appStorage($demo_app));
        $this->assertEquals(4.00, $app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price']);
    }

    public function test_adding_emails_pricing()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->disableApps();

        $this->withoutExceptionHandling();
        $user = User::find(1);

        $domains = $user->organization->domains()->update(['email_enabled' => true]);
        $this->actingAs($user);
        $this->followingRedirects();

        $demo_app = $support->demo_app->instances()->first();
        $email_server = new Server;
        $email_server->name = 'Email';
        $email_server->host = 'localhost';
        $email_server->address = 'localhost';
        $email_server->api_key = 'localhost';
        $email_server->api_secret = 'localhost';
        $email_server->ip = '127.0.0.1';
        $email_server->type = 'email';
        $email_server->interface = 'ldap';
        $email_server->default_email_server = true;
        $email_server->status = 'active';
        $email_server->save();
        $support->base_unlimited->email_enabled = true;
        $support->base_unlimited->email_server_id = $email_server->id;
        $support->base_unlimited->save();

        $support->setSubscription($user->organization, $support->base_unlimited);
        $base_pricing = Subscription::refresh()->base();

        $domain = $user->organization->domains()->first();

        $domain1 = $this->post('/settings/email/accounts', [
            'name' => 'test1',
            'email' => 'test1',
            'domain' => $user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $domain1->assertSee('test1');
        $this->followingRedirects();

        $this->assertEquals(2.00, $base_pricing->optionStats('email')['total_price']);

        $domain2 = $this->post('/settings/email/accounts', [
            'name' => 'test2',
            'email' => 'test2',
            'domain' => $user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain2->assertSee('test2');
        $this->assertEquals(4.00, $base_pricing->optionStats('email')['total_price']);

        $support->base_1->email_enabled = true;
        $support->base_1->email_server_id = $email_server->id;
        $support->base_1->save();
        $support->setSubscription($user->organization, $support->base_1);
        $base_pricing = Subscription::refresh()->base();

        $this->assertEquals(2.00, $base_pricing->optionStats('email')['total_price']);
    }

    public function test_stripe_pricing()
    {
        if (env('ACCOUNTMANAGER_DRIVER') !== 'ldap') {
            return;
        }

        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);
        $this->followingRedirects();
        $support->base_1->payment_enabled = true;
        $support->base_1->save();

        $demo_app = $support->demo_app->instances()->first();
        $support->setSubscription($user->organization, $support->base_1);
        Subscription::refresh();
        $base_pricing = Subscription::base();
        $stripe = $base_pricing->stripePricing();

        $this->assertEquals(1, Arr::get($stripe, 'stripe_base.quantity'));
        $this->assertEquals(0, Arr::get($stripe, 'stripe_basic.quantity'));
        $this->assertEquals(0, Arr::get($stripe, 'stripe_email.quantity'));
        $this->assertEquals(0, Arr::get($stripe, 'stripe_storage.quantity'));
        $this->assertEquals(1, Arr::get($stripe, 'stripe_standard.quantity'));
        $this->assertEquals(1, Arr::get($stripe, 'stripe_application.quantity'));
    }
}
