<?php

use App\Events\Users\UserStorageUpdated;
use App\Organization;
use App\Plan;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Event;

// The Feature/Subscription beforeEach in Pest.php seeds, activates the demo app, creates the
// demo app plans, base_2 and users. base_1 is a Pay per App plan (type 'app') and base_2 a
// Packaged plan (type 'package'). demo_app_2 has base/standard/basic/storage prices.

function appInstanceService(object $test, string $app_plan): AppInstancePlanService
{
    return new AppInstancePlanService($test->support->{$app_plan}, $test->demoApp);
}

function useBasePlan(Plan $plan): void
{
    OrganizationFacade::setOrganization(Organization::find(1));
    Subscription::all();
    Subscription::updateBase($plan);
}

beforeEach(function () {
    Event::fake([UserStorageUpdated::class]);
});

// ---------------------------------------------------------------------------
// Packaged plans never bill through their app plans
// ---------------------------------------------------------------------------

it('bills an app instance through its own app plan under a Pay per App base plan', function () {
    useBasePlan($this->support->base_1);
    $service = appInstanceService($this, 'demo_app_2');

    expect($service->isPackaged())->toBeFalse();
    expect($service->pricingOptions())->not->toBeEmpty();
    expect($service->pricing())->not->toBeEmpty();
    expect($service->stats())->not->toBeEmpty();
    expect($service->totalPrice())->toBeGreaterThan(0);
});

it('does not bill an app instance through its app plan under a Packaged base plan', function () {
    useBasePlan($this->support->base_2);
    $service = appInstanceService($this, 'demo_app_2');

    // demo_app_2 is payment enabled and carries base, standard, basic and storage prices
    expect($service->setting('base.price_id'))->not->toBeNull();
    expect($service->isPackaged())->toBeTrue();
    expect($service->pricingOptions())->toBe([]);
    expect($service->pricing())->toBe([]);
    expect($service->stats())->toBe([]);
    expect($service->totalPrice())->toBe(0.0);
});

it('leaves app instance plans out of the cost stats and total under a Packaged base plan', function () {
    $this->support->base_2->payment_enabled = true;
    $this->support->base_2->save();
    useBasePlan($this->support->base_2);
    Subscription::updateApp($this->support->demo_app_2, $this->demoApp);
    Subscription::refresh();

    foreach (Subscription::paidSubscriptions() as $plan) {
        if ($plan instanceof AppInstancePlanService) {
            expect($plan->stats())->toBe([]);
            expect($plan->totalPrice())->toBe(0.0);
        }
    }
    expect(Subscription::compileAllSubscriptionInfo())->not->toHaveKey($this->demoApp->id);
});

// ---------------------------------------------------------------------------
// Email is billed at the organization level under Pay per App
// ---------------------------------------------------------------------------

it('bills only email through a Pay per App base plan', function () {
    OrganizationFacade::setOrganization(Organization::find(1));
    $service = new BasePlanService(Organization::find(1), $this->support->base_1);

    expect($this->support->base_1->type)->toBe('app');
    expect(array_keys($service->pricingOptions()))->toBe(['email']);
    expect($service->pricingOptions()['email']['price_id'])->toBe('stripe_email');
    expect(array_keys($service->stats()))->toBe(['email']);
    // The organization has no email accounts, so nothing is owed yet
    expect($service->totalPrice())->toBe(0);
});

it('does not bill base, users, storage or applications through a Pay per App base plan', function () {
    OrganizationFacade::setOrganization(Organization::find(1));
    $service = new BasePlanService(Organization::find(1), $this->support->base_1);

    $app_plan_entities = ['base', 'standard', 'basic', 'storage', 'application'];

    expect(array_intersect(array_keys($service->pricingOptions()), $app_plan_entities))->toBe([]);
    expect(array_intersect(array_keys($service->stats()), $app_plan_entities))->toBe([]);
});

it('bills every entity through a Packaged base plan', function () {
    $this->support->base_2->update(['settings' => array_replace_recursive($this->support->base_2->settings, [
        'base' => ['price_id' => 'pkg_base'],
        'standard' => ['price_id' => 'pkg_standard'],
        'email' => ['price_id' => 'pkg_email'],
    ])]);
    OrganizationFacade::setOrganization(Organization::find(1));
    $service = new BasePlanService(Organization::find(1), $this->support->base_2->refresh());

    expect($service->pricingOptions())->toHaveKeys(['base', 'standard', 'email']);
});

// ---------------------------------------------------------------------------
// Per-user storage: the package wins under a Packaged base plan
// ---------------------------------------------------------------------------

it('uses the app plan per-user storage under a Pay per App base plan', function () {
    useBasePlan($this->support->base_1);
    $service = appInstanceService($this, 'demo_app_1');

    expect($service->userStorage('standard'))->toBe(1);
    expect($service->userStorage('basic'))->toBe(0.5);
});

it('uses the package per-user storage under a Packaged base plan', function () {
    useBasePlan($this->support->base_2);
    $service = appInstanceService($this, 'demo_app_1');

    // base_2 sets 2 GB for both; demo_app_1 would give 1 and 0.5
    expect($service->userStorage('standard'))->toBe(2);
    expect($service->userStorage('basic'))->toBe(2);
    expect($service->standardStorageTotal())->toBe((float) ($service->countEntity('standard') * 2));
});

it('falls back to the app plan per-user storage when the package leaves it blank', function () {
    $package = Plan::factory()->create(['type' => 'package']);
    expect($package->setting('standard.storage'))->toBeNull();
    useBasePlan($package);
    $service = appInstanceService($this, 'demo_app_1');

    expect($service->isPackaged())->toBeTrue();
    expect($service->userStorage('standard'))->toBe(1);
    expect($service->userStorage('basic'))->toBe(0.5);
});

it('refreshes per-user storage when moving to a Packaged plan with different storage', function () {
    useBasePlan($this->support->base_1);
    Event::fake([UserStorageUpdated::class]);

    Subscription::updateBase($this->support->base_2);

    Event::assertDispatched(UserStorageUpdated::class, function ($event) {
        return $event->organization->id === 1 && $event->app_instance === null && $event->user_id === null;
    });
});

it('does not refresh per-user storage when a Packaged plan keeps the same storage', function () {
    $twin = Plan::factory()->create(['type' => 'package', 'settings' => $this->support->base_2->settings]);
    useBasePlan($this->support->base_2);
    Event::fake([UserStorageUpdated::class]);

    Subscription::updateBase($twin);

    Event::assertNotDispatched(UserStorageUpdated::class);
});

it('does not refresh per-user storage when an app plan changes under a Packaged plan', function () {
    useBasePlan($this->support->base_2);
    Subscription::updateApp($this->support->demo_app_1, $this->demoApp);
    Event::fake([UserStorageUpdated::class]);

    // demo_app_2 stores a different standard.storage (2) than demo_app_1 (1), but the package decides
    Subscription::updateApp($this->support->demo_app_2, $this->demoApp);

    Event::assertNotDispatched(UserStorageUpdated::class);
});
