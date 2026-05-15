<?php

use App\Organization;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Tests\Support\TestSupports;

it('manages subscriptions via service', function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createBase2Plan();
    $support->createBaseWithSpecificPlans();

    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $demo_app_instance = $support->demo_app->instances()->where('organization_id', $organization->id)->first();
    Subscription::all();
    Subscription::updateBase($support->base_1);

    expect(count(Subscription::paidSubscriptions()))->toBe(0);
    expect(Subscription::domainsEnabled())->toBeFalse();
    expect(Subscription::emailEnabled())->toBeFalse();
    Subscription::updateBase($support->base_1);

    expect(Subscription::base())->toBeInstanceOf(BasePlanService::class);
    expect(Subscription::app_instance($demo_app_instance))->toBeInstanceOf(AppInstancePlanService::class);

    Subscription::updateBase($support->base_2);
    expect(Subscription::domainsEnabled())->toBeTrue();
    expect(Subscription::emailEnabled())->toBeTrue();

    $paid_plans = Subscription::paidSubscriptions();
    expect(count($paid_plans))->toBe(1);
    foreach ($paid_plans as $plan) {
        expect($plan->payment_enabled)->toBeTrue();
    }

    Subscription::updateBase($support->base_with_specific_app_plans);
    $demo_app_instance->refresh();
    expect($demo_app_instance->plan->id)->toBe(Subscription::app_instance($demo_app_instance)->id);

    Subscription::refresh();
    $app_plans = Subscription::appInstancePlans();
    expect(count($app_plans))->toBe(1);
    foreach ($app_plans as $plan) {
        expect($plan)->toBeInstanceOf(AppInstancePlanService::class);
    }
});
