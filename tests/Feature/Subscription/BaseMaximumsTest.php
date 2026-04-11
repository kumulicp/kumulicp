<?php

namespace Tests\Feature\Subscription;

use App\Server;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class BaseMaximumsTest extends TestCase
{
    use RefreshDatabase;

    public function test_max_standard_users_reached()
    {
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

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_1, $demo_app);

        $this->assertFalse(AccountManager::users()->find('testing1')->canAccessApp($demo_app));
        $this->assertFalse(AccountManager::users()->find('testing2')->canAccessApp($demo_app));

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        // $this->followingRedirects();
        $permission2 = $this->post('/users/testing2/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertTrue(AccountManager::users()->find('testing1')->canAccessApp($demo_app));
        $this->assertFalse(AccountManager::users()->find('testing2')->canAccessApp($demo_app));

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_2, $demo_app);

        $permission2 = $this->post('/users/testing2/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $this->assertTrue(AccountManager::users()->find('testing2')->canAccessApp($demo_app));
    }

    public function test_max_additional_storage_reached()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        $user = User::find(1);
        $this->actingAs($user);

        $demo_app = $support->demo_app->instances()->first();

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_2, $demo_app);

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
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

        $edit1->assertSessionDoesntHaveErrors();

        $this->assertEquals(4, AccountManager::users()->find('testing1')->appStorage($demo_app));

        $permission2 = $this->post('/users/testing2/permissions', [
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

        // Should now equal 10
        $this->assertEquals(4, AccountManager::users()->find('testing2')->appStorage($demo_app));
        $edit2 = $this->put('/users/testing2', [
            'first_name' => 'test',
            'last_name' => 'user2',
            'personal_email' => 'test2@example.com',
            'organization' => $user->organization->id,
            'additional_storage' => [
                $demo_app->id => 100,
            ],
        ]);

        // Should still equal 10 because the limit is 3
        $this->assertEquals(4, AccountManager::users()->find('testing2')->appStorage($demo_app));
    }

    public function test_max_basic_users_reached()
    {
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

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_1, $demo_app);

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $permission2 = $this->post('/users/testing2/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertTrue(AccountManager::users()->find('testing1')->canAccessApp($demo_app));
        $this->assertEquals('basic', AccountManager::users()->find('testing1')->appUserAccessType($demo_app));
        $this->assertFalse(AccountManager::users()->find('testing2')->canAccessApp($demo_app));
        $this->assertEquals('none', AccountManager::users()->find('testing2')->appUserAccessType($demo_app));

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_2, $demo_app);

        $permission2 = $this->post('/users/testing2/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $this->assertTrue(AccountManager::users()->find('testing2')->canAccessApp($demo_app));
        $this->assertEquals('basic', AccountManager::users()->find('testing2')->appUserAccessType($demo_app));
    }

    public function test_max_domains_reached()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->disableApps();

        $user = User::find(1);

        $user->organization->domains()->delete();
        $this->actingAs($user);
        $this->followingRedirects();

        $demo_app = $support->demo_app->instances()->first();
        $support->setSubscription($user->organization, $support->base_2, $support->demo_app_2, $demo_app);

        $domain1 = $this->post('/settings/domains/connect', [
            'domain_name' => 'example1.com',
        ]);

        $domain1->assertSee('example1.com');
        $this->followingRedirects();

        $domain2 = $this->post('/settings/domains/connect', [
            'domain_name' => 'example2.com',
        ]);
        $domain2->assertSee('example2.com');

        $domain3 = $this->post('/settings/domains/connect', [
            'domain_name' => 'example3.com',
        ]);
        $domain3->assertStatus(403);
    }

    public function test_max_emails_reached()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->disableApps();

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
        $support->base_2->email_enabled = true;
        $support->base_2->email_server_id = $email_server->id;
        $support->base_2->save();

        $support->setSubscription($user->organization, $support->base_2);
        Subscription::refresh();

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

        $domain2 = $this->post('/settings/email/accounts', [
            'name' => 'test2',
            'email' => 'test2',
            'domain' => $user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain2->assertSee('test2');

        $domain3 = $this->post('/settings/email/accounts', [
            'name' => 'test3',
            'email' => 'test3',
            'domain' => $user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $domain3->assertStatus(403);
    }
}
