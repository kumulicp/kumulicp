<?php

namespace Tests\Feature\Email;

use App\Support\Facades\Subscription;
use Tests\Feature\Subscription\SubscriptionTestCase;

class EmailMaximumsTest extends SubscriptionTestCase
{
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
