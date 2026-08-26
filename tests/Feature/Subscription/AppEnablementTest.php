<?php

use App\Application;
use App\Organization;
use App\Plan;
use App\Services\Organization\BasePlanService;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates demo app
// (base_1's app_plans includes 'demo_app' => ['max' => 1, 'plans' => 'enabled']),
// creates demo app plans, creates base_2, adds users, and acts as the demo user.
// $this->support, $this->user, $this->demoApp are available.
// AppFacade::initialize() creates the demo_app Application record with enabled=false
// (a separate, platform-wide toggle unrelated to plan enablement), so it's flipped
// to true here to isolate these tests to the plan-based enablement logic.

beforeEach(function () {
    $this->support->demo_app->enabled = true;
    $this->support->demo_app->save();
});

it('enables an app that is configured in the plan app_plans', function () {
    $organization = Organization::find(1);
    $service = new BasePlanService($organization, $this->support->base_1);

    expect($service->appEnabled($this->support->demo_app))->toBeTrue();
    expect($service->enabledApps()->pluck('slug'))->toContain('demo_app');
});

it('does not enable an app that is absent from the plan app_plans', function () {
    $organization = Organization::find(1);
    $plan = Plan::factory()->create(['app_plans' => []]);
    $service = new BasePlanService($organization, $plan);

    expect($service->appEnabled($this->support->demo_app))->toBeFalse();
    expect($service->enabledApps()->pluck('slug'))->not->toContain('demo_app');
});

it('does not enable an app whose plan list is empty', function () {
    $organization = Organization::find(1);
    $plan = Plan::factory()->create([
        'app_plans' => ['demo_app' => ['max' => 1, 'plans' => []]],
    ]);
    $service = new BasePlanService($organization, $plan);

    expect($service->appEnabled($this->support->demo_app))->toBeFalse();
    expect($service->enabledApps()->pluck('slug'))->not->toContain('demo_app');
});

it('does not enable an app whose plan list is null', function () {
    $organization = Organization::find(1);
    $plan = Plan::factory()->create([
        'app_plans' => ['demo_app' => ['max' => 1, 'plans' => null]],
    ]);
    $service = new BasePlanService($organization, $plan);

    expect($service->appEnabled($this->support->demo_app))->toBeFalse();
    expect($service->enabledApps()->pluck('slug'))->not->toContain('demo_app');
});

it('enables an app configured with a real list of app plan ids', function () {
    $organization = Organization::find(1);
    $plan = Plan::factory()->create([
        'app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$this->support->demo_app_1->id]]],
    ]);
    $service = new BasePlanService($organization, $plan);

    expect($service->appEnabled($this->support->demo_app))->toBeTrue();
    expect($service->enabledApps()->pluck('slug'))->toContain('demo_app');
});

it('excludes a globally disabled application even when the plan enables it', function () {
    $organization = Organization::find(1);
    $disabled_app = Application::factory()->create(['slug' => 'disabled_app', 'enabled' => 0]);
    $plan = Plan::factory()->create([
        'app_plans' => ['disabled_app' => ['max' => 1, 'plans' => 'enabled']],
    ]);
    $service = new BasePlanService($organization, $plan);

    expect($service->appEnabled($disabled_app))->toBeTrue();
    expect($service->enabledApps()->pluck('slug'))->not->toContain('disabled_app');
});
