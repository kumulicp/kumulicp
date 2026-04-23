<?php

namespace Tests\Feature\Subscription;

use App\Support\Facades\AccountManager;
use App\Support\Facades\Subscription;

class BaseMaximumsTest extends SubscriptionTestCase
{
    public function test_max_standard_users_reached()
    {
        $this->withoutExceptionHandling();
        $this->followingRedirects();

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

        $this->assertFalse(AccountManager::users()->find('testing1')->canAccessApp($this->demoApp));
        $this->assertFalse(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp));

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);
        $this->grantPermission('testing2', $this->demoApp->id, ['demo_role']);

        $this->assertTrue(AccountManager::users()->find('testing1')->canAccessApp($this->demoApp));
        $this->assertFalse(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp));

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

        $this->grantPermission('testing2', $this->demoApp->id, ['demo_role']);
        $this->assertTrue(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp));
    }

    public function test_max_additional_storage_reached()
    {
        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);
        $edit1 = $this->put('/users/testing1', [
            'first_name' => 'test',
            'last_name' => 'user1',
            'personal_email' => 'test1@example.com',
            'organization' => $this->user->organization->id,
            'additional_storage' => [$this->demoApp->id => 1],
        ]);

        $edit1->assertSessionDoesntHaveErrors();
        $this->assertEquals(4, AccountManager::users()->find('testing1')->appStorage($this->demoApp));

        $this->grantPermission('testing2', $this->demoApp->id, ['demo_role']);
        $this->put('/users/testing2', [
            'first_name' => 'test',
            'last_name' => 'user2',
            'personal_email' => 'test2@example.com',
            'organization' => $this->user->organization->id,
            'additional_storage' => [$this->demoApp->id => 1],
        ]);

        $this->assertEquals(4, AccountManager::users()->find('testing2')->appStorage($this->demoApp));

        $this->put('/users/testing2', [
            'first_name' => 'test',
            'last_name' => 'user2',
            'personal_email' => 'test2@example.com',
            'organization' => $this->user->organization->id,
            'additional_storage' => [$this->demoApp->id => 100],
        ]);

        $this->assertEquals(4, AccountManager::users()->find('testing2')->appStorage($this->demoApp));
    }

    public function test_max_basic_users_reached()
    {
        $this->withoutExceptionHandling();
        $this->followingRedirects();

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

        $this->grantPermission('testing1', $this->demoApp->id, ['basic_demo_role']);
        $this->grantPermission('testing2', $this->demoApp->id, ['basic_demo_role']);

        $this->assertTrue(AccountManager::users()->find('testing1')->canAccessApp($this->demoApp));
        $this->assertEquals('basic', AccountManager::users()->find('testing1')->appUserAccessType($this->demoApp));
        $this->assertFalse(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp));
        $this->assertEquals('none', AccountManager::users()->find('testing2')->appUserAccessType($this->demoApp));

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

        $this->grantPermission('testing2', $this->demoApp->id, ['basic_demo_role']);
        $this->assertTrue(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp));
        $this->assertEquals('basic', AccountManager::users()->find('testing2')->appUserAccessType($this->demoApp));
    }

    public function test_max_domains_reached()
    {
        $this->followingRedirects();
        $this->user->organization->domains()->delete();

        $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

        $domain1 = $this->post('/settings/domains/connect', ['domain_name' => 'example1.com']);
        $domain1->assertSee('example1.com');

        $domain2 = $this->post('/settings/domains/connect', ['domain_name' => 'example2.com']);
        $domain2->assertSee('example2.com');

        $domain3 = $this->post('/settings/domains/connect', ['domain_name' => 'example3.com']);
        $domain3->assertStatus(403);
    }

    public function test_max_emails_reached()
    {
        $this->followingRedirects();
        $this->user->organization->domains()->update(['email_enabled' => true]);

        $emailServer = $this->createEmailServer();
        $this->support->base_2->email_enabled = true;
        $this->support->base_2->email_server_id = $emailServer->id;
        $this->support->base_2->save();

        $this->support->setSubscription($this->user->organization, $this->support->base_2);
        Subscription::refresh();

        $domain1 = $this->post('/settings/email/accounts', [
            'name' => 'test1',
            'email' => 'test1',
            'domain' => $this->user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain1->assertSee('test1');

        $domain2 = $this->post('/settings/email/accounts', [
            'name' => 'test2',
            'email' => 'test2',
            'domain' => $this->user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain2->assertSee('test2');

        $domain3 = $this->post('/settings/email/accounts', [
            'name' => 'test3',
            'email' => 'test3',
            'domain' => $this->user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain3->assertStatus(403);
    }
}
