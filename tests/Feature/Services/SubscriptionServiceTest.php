<?php

use App\Events\Users\UserStorageUpdated;
use App\Organization;
use App\Plan;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Services\SubscriptionService;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Event;
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

it('only dispatches a scoped storage update when an app plan change alters per-user storage', function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();

    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $demo_app_instance = $support->demo_app->instances()->where('organization_id', $organization->id)->first();

    $subscription = (new SubscriptionService($organization))->all();
    $subscription->updateApp($support->demo_app_1, $demo_app_instance);

    Event::fake([UserStorageUpdated::class]);

    // Re-applying the same plan does not change standard.storage, so nothing is dispatched.
    $subscription->updateApp($support->demo_app_1, $demo_app_instance);
    Event::assertNotDispatched(UserStorageUpdated::class);

    // demo_app_2 has a different standard.storage amount than demo_app_1 (2 vs 1),
    // so the event should fire, scoped to this organization and app instance only.
    $subscription->updateApp($support->demo_app_2, $demo_app_instance);

    Event::assertDispatched(UserStorageUpdated::class, function ($event) use ($organization, $demo_app_instance) {
        return $event->organization->is($organization)
            && $event->app_instance->is($demo_app_instance)
            && $event->user_id === null;
    });
    Event::assertDispatchedTimes(UserStorageUpdated::class, 1);
});

it('cascades a scoped storage update to affected apps when the base plan changes', function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();

    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $demo_app_instance = $support->demo_app->instances()->where('organization_id', $organization->id)->first();

    $subscription = (new SubscriptionService($organization))->all();
    $subscription->updateApp($support->demo_app_1, $demo_app_instance);

    // Pin demo_app to a single specific app plan (demo_app_2), whose standard.storage (2)
    // differs from demo_app_1's (1), so switching the base plan should cascade the change.
    $base_plan = Plan::factory()->create([
        'app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$support->demo_app_2->id]]],
    ]);

    Event::fake([UserStorageUpdated::class]);

    $subscription->updateBase($base_plan);

    Event::assertDispatched(UserStorageUpdated::class, function ($event) use ($organization, $demo_app_instance) {
        return $event->organization->is($organization)
            && $event->app_instance->is($demo_app_instance)
            && $event->user_id === null;
    });
});
