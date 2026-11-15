<?php

use App\Integrations\Billing\Stripe\StripeGateway;
use App\Organization;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\SubscriptionService;
use App\Support\Facades\Organization as OrganizationFacade;
use App\User;
use Illuminate\Support\Facades\Facade;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates the demo app and acts as the demo user.

beforeEach(function () {
    $this->organization = Organization::find(1);
    OrganizationFacade::setOrganization($this->organization);
    $currency = $this->organization->billingCurrency();

    // A priced, payment enabled app plan on a Pay per App base plan, so the app plan is what gets billed
    $this->app_plan = $this->support->demo_app_2;
    $this->app_plan->settings = array_merge($this->app_plan->settings ?? [], [
        'base' => ['prices' => [$currency => ['amount' => 7, 'price_id' => 'price_app_base']]],
    ]);
    $this->app_plan->payment_enabled = true;
    $this->app_plan->save();

    $this->support->base_1->app_plans = ['demo_app' => ['max' => 5, 'plans' => [$this->app_plan->id]]];
    $this->support->base_1->save();
    $this->organization->forceFill(['plan_id' => $this->support->base_1->id])->save();
    $this->demoApp->plan()->associate($this->app_plan);
});

function billedPriceIds($test): array
{
    $subscription = (new SubscriptionService($test->organization))->all();

    return array_keys((new StripeGateway($test->organization))->pricingFromPlans($subscription->paidSubscriptions()));
}

it('leaves an app out of the billing while it is in its free trial', function () {
    $this->demoApp->forceFill(['trial_ends_at' => now()->addDay()])->save();

    $service = new AppInstancePlanService($this->app_plan, $this->demoApp->fresh());

    expect($service->inTrial())->toBeTrue();
    expect($service->pricingOptions())->toBe([]);
    expect($service->totalPrice())->toBe(0.0);
    expect(billedPriceIds($this))->not->toContain('price_app_base');
});

it('bills the app once the trial is over', function () {
    $this->demoApp->forceFill(['trial_ends_at' => now()->subMinute()])->save();
    expect((new AppInstancePlanService($this->app_plan, $this->demoApp->fresh()))->inTrial())->toBeFalse();
    expect(billedPriceIds($this))->toContain('price_app_base');

    // ExpiredSubscriptionsCheck clears a finished trial
    $this->demoApp->forceFill(['trial_ends_at' => null])->save();
    expect(billedPriceIds($this))->toContain('price_app_base');
});

it('counts a trial that ends later today as still running', function () {
    $this->demoApp->forceFill(['trial_ends_at' => now()->addHour()])->save();

    // The 'date' cast would turn this into this morning
    expect($this->demoApp->fresh()->inTrial())->toBeTrue();
});

it('tells the customer they are not charged yet in the summaries and on the plans page', function () {
    $this->demoApp->forceFill(['trial_ends_at' => now()->addDay()])->save();
    $notice = __('organization.subscription.trial_notice', ['date' => now()->addDay()->format('M d, Y')]);

    $subscription = (new SubscriptionService($this->organization))->all();
    $stats = collect($subscription->compileCostStats())->firstWhere('name', $this->demoApp->label);

    expect($stats['notice'])->toBe($notice);
    expect($notice)->toContain("won't be charged")->toContain('first payment date');

    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    $this->actingAs(User::findOrFail($this->user->id));
    $page = $this->get('/subscription/plans')->assertOk();

    $row = collect($page->viewData('page')['props']['plans'])->firstWhere('entity.id', $this->demoApp->id);
    expect($row['notice'])->toBe($notice);
});

it('has no notice for an app that is not in a trial', function () {
    $service = new AppInstancePlanService($this->app_plan, $this->demoApp->fresh());

    expect($service->trialNotice())->toBeNull();
});
