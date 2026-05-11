<?php

namespace Tests\Feature\Subscription;

use App\Enums\PlanEntity;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class PricingTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresLdap();
    }

    public function test_base_pricing_change()
    {
        $this->withoutExceptionHandling();
        $this->followingRedirects();

        $this->support->base_1->payment_enabled = true;
        $this->support->base_1->save();

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

        $base_pricing = Subscription::refresh()->base();

        $this->assertEquals(1.00, $base_pricing->optionStats('base')['total_price']);
        $this->assertEquals(1.00, $base_pricing->optionStats('standard')['total_price']);
        $this->assertEquals(0.00, $base_pricing->optionStats('basic')['total_price']);
        $this->assertEquals(0.00, $base_pricing->optionStats('storage')['total_price']);
    }

    public function test_adding_standard_users_pricing()
    {
        $this->withoutExceptionHandling();

        $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

        $base_pricing = Subscription::base();
        $this->assertEquals(2.00, $base_pricing->optionStats('standard')['total_price']);

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);
        $this->assertEquals(4.00, $base_pricing->optionStats('standard')['total_price']);
    }

    public function test_adding_basic_users_pricing()
    {
        $this->withoutExceptionHandling();
        $this->followingRedirects();

        $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

        $base_pricing = Subscription::all()->base();
        $this->assertEquals(0.00, $base_pricing->optionStats('basic')['total_price']);

        $this->grantPermission('testing1', $this->demoApp->id, ['basic_demo_role']);
        $this->assertEquals(2.00, $base_pricing->optionStats('basic')['total_price']);

        $this->grantPermission('testing2', $this->demoApp->id, ['basic_demo_role']);
        $this->assertEquals(4.00, $base_pricing->optionStats('basic')['total_price']);

        $this->post('/users', [
            'username' => 'testing3',
            'first_name' => 'test',
            'last_name' => 'user3',
            'personal_email' => 'test3@example.com',
        ]);

        $this->grantPermission('testing3', $this->demoApp->id, ['basic_demo_role']);
        $this->assertEquals(4.00, $base_pricing->optionStats('basic')['total_price']);
    }

    public function test_adding_additional_storage_pricing()
    {
        Http::fake([
            'https://demo-nextcloud.example.com:443/ocs/v1.php/cloud/users/testing1' => ['hey' => 'there'],
        ]);

        $this->withoutExceptionHandling();
        Organization::setOrganization($this->user->organization);
        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

        $this->followingRedirects();
        $this->post('/users/testing1/permissions', [
            'permission' => [
                1 => 'none',
                2 => ['none'],
                $this->demoApp->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $this->put('/users/testing1', [
            'first_name' => 'test',
            'last_name' => 'user1',
            'personal_email' => 'test1@example.com',
            'organization' => $this->user->organization->id,
            'additional_storage' => [$this->demoApp->id => 1],
        ]);

        $app_pricing = Subscription::app_instance($this->demoApp);
        $this->assertEquals(2.00, $app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price']);
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
        $this->assertEquals(4.00, $app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price']);
    }

    public function test_stripe_pricing()
    {
        $this->withoutExceptionHandling();
        $this->followingRedirects();

        $this->support->base_1->payment_enabled = true;
        $this->support->base_1->save();

        $this->support->setSubscription($this->user->organization, $this->support->base_1);
        $base_pricing = Subscription::refresh()->base();
        $stripe = $base_pricing->stripePricing();

        $this->assertEquals(1, Arr::get($stripe, 'stripe_base.quantity'));
        $this->assertEquals(0, Arr::get($stripe, 'stripe_basic.quantity'));
        $this->assertEquals(0, Arr::get($stripe, 'stripe_email.quantity'));
        $this->assertEquals(0, Arr::get($stripe, 'stripe_storage.quantity'));
        $this->assertEquals(1, Arr::get($stripe, 'stripe_standard.quantity'));
        $this->assertEquals(1, Arr::get($stripe, 'stripe_application.quantity'));
    }
}
