<?php

namespace Tests\Feature\Services;

use App\AppInstance;
use App\AppPlan;
use App\Organization;
use App\Plan;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_service()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->disableApps();

        $organization = Organization::find(1);
        OrganizationFacade::setOrganization($organization);
        $demo_app_instance = $support->demo_app->instances()->where('organization_id', $organization->id)->first();
        Subscription::all();
        Subscription::updateBase($support->base_1);
        /* Test domain/email enabled functions */
        $this->assertEquals(0, count(Subscription::paidSubscriptions()));
        $this->assertFalse(Subscription::domainsEnabled());
        $this->assertFalse(Subscription::emailEnabled());
        Subscription::updateBase($support->base_1);

        $this->assertInstanceOf(BasePlanService::class, Subscription::base());

        $this->assertInstanceOf(AppInstancePlanService::class, Subscription::app_instance($demo_app_instance));

        Subscription::updateBase($support->base_2);
        $this->assertTrue(Subscription::domainsEnabled());
        $this->assertTrue(Subscription::emailEnabled());

        $paid_plans = Subscription::paidSubscriptions();
        $this->assertEquals(1, count($paid_plans));
        foreach ($paid_plans as $plan) {
            $this->assertTrue($plan->payment_enabled);
        }

        /* Test update also updates app_instances subscriptions */
        Subscription::updateBase($support->base_with_specific_app_plans);
        $demo_app_instance->refresh();
        $this->assertEquals($demo_app_instance->plan->id, Subscription::app_instance($demo_app_instance)->id);

        Subscription::refresh();
        $app_plans = Subscription::appInstancePlans();
        $this->assertEquals(1, count($app_plans));
        foreach ($app_plans as $plan) {
            $this->assertInstanceOf(AppInstancePlanService::class, $plan);
        }/*


        Subscription::updateApp(AppPlan $plan, AppInstance $app_instance);
        Subscription::refresh();
        Subscription::compileAllStripePricing();

        Subscription::compileAllSubscriptionInfo();

        Subscription::compileAllStats();

        Subscription::compileCostStats();

        Subscription::get();

        Subscription::appInstanceSubscription($nextcloud, $subscription_support->demo_app);

        Subscription::dryBaseChange(Plan $plan);

        Subscription::dryAppChange($nextcloud, AppPlan $plan);*/
    }
}
