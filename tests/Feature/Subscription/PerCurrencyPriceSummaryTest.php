<?php

use App\Organization;
use App\Plan;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;

// Plans keep their prices per currency in the plan_prices table, so the subscription summaries
// have to read those, not the flat legacy settings.price.
// The Feature/Subscription beforeEach in Pest.php already seeds and activates the demo app.

it('builds the base plan summary from per-currency prices', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $currency = $organization->billingCurrency();

    $plan = Plan::factory()->create([
        'type' => 'package',
        'payment_enabled' => true,
        'settings' => [
            'standard' => ['max' => 10, 'storage' => 1],
        ],
    ]);
    $plan->setPrice('standard', $currency, 50, 'pkg_std');

    $service = new BasePlanService($organization, $plan->fresh());
    $users = $service->standardStats()['quantity'];

    expect($service->stats())->toHaveKey('standard');
    expect($service->stats()['standard']['price'])->toEqual(50);
    expect($service->totalPrice())->toEqual(50 * $users);
});

it('builds an app instance summary from per-currency app plan prices', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $currency = $organization->billingCurrency();

    $app_plan = $this->support->demo_app_2;
    $app_plan->settings = array_merge($app_plan->settings ?? [], [
        'standard' => ['max' => 10, 'storage' => 1],
    ]);
    $app_plan->save();
    $app_plan->setPrice('base', $currency, 7, 'app_base');
    $app_plan->setPrice('standard', $currency, 3, 'app_std');

    // Pay per App base plan, so the app plan is what gets billed
    OrganizationFacade::account()->forceFill(['plan_id' => $this->support->base_1->id])->save();
    $service = new AppInstancePlanService($app_plan->fresh(), $this->demoApp);

    expect($service->stats())->toHaveKey('base');
    expect($service->stats()['base']['price'])->toEqual(7);
    expect($service->totalPrice())->toBeGreaterThanOrEqual(7);
});

it('keeps the summaries of two instances on the same app plan apart', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $currency = $organization->billingCurrency();

    $app_plan = $this->support->demo_app_2;
    $app_plan->settings = array_merge($app_plan->settings ?? [], [
        'standard' => ['max' => 10, 'storage' => 1],
    ]);
    $app_plan->save();
    $app_plan->setPrice('standard', $currency, 3, 'app_std');
    OrganizationFacade::account()->forceFill(['plan_id' => $this->support->base_1->id])->save();

    $second = $this->demoApp->replicate();
    $second->label = 'Second';
    $second->save();

    $first_stats = (new AppInstancePlanService($app_plan->fresh(), $this->demoApp))->stats();
    $second_stats = (new AppInstancePlanService($app_plan->fresh(), $second))->stats();

    // Cached per plan only, the second instance used to be handed the first one's numbers
    expect($first_stats)->toHaveKey('standard');
    expect($second_stats)->toHaveKey('standard');
    expect(FastCache::retrieve('app_plan_stats_'.$second->id.'_'.$app_plan->id, fn () => 'missing'))->not->toBe('missing');
});
