<?php

namespace Tests\Feature\Email;

use App\Support\Facades\Subscription;
use Tests\Feature\Subscription\SubscriptionTestCase;

class EmailPricingTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresLdap();
    }

    public function test_adding_emails_pricing()
    {
        $this->withoutExceptionHandling();
        $this->followingRedirects();

        $this->user->organization->domains()->update(['email_enabled' => true]);

        $emailServer = $this->createEmailServer();
        $this->support->base_unlimited->email_enabled = true;
        $this->support->base_unlimited->email_server_id = $emailServer->id;
        $this->support->base_unlimited->save();

        $this->support->setSubscription($this->user->organization, $this->support->base_unlimited);
        $base_pricing = Subscription::refresh()->base();

        $domain1 = $this->post('/settings/email/accounts', [
            'name' => 'test1',
            'email' => 'test1',
            'domain' => $this->user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain1->assertSee('test1');
        $this->assertEquals(2.00, $base_pricing->optionStats('email')['total_price']);

        $domain2 = $this->post('/settings/email/accounts', [
            'name' => 'test2',
            'email' => 'test2',
            'domain' => $this->user->organization->domains()->first()->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $domain2->assertSee('test2');
        $this->assertEquals(4.00, $base_pricing->optionStats('email')['total_price']);

        $this->support->base_1->email_enabled = true;
        $this->support->base_1->email_server_id = $emailServer->id;
        $this->support->base_1->save();
        $this->support->setSubscription($this->user->organization, $this->support->base_1);
        $base_pricing = Subscription::refresh()->base();

        $this->assertEquals(2.00, $base_pricing->optionStats('email')['total_price']);
    }
}
