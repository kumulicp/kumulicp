<?php

use App\Organization;
use App\Plan;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\Organization\BasePlanService;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates the demo app and acts as the demo user.

function planWithDemoAppMax(Organization $organization, int $max): Plan
{
    return Plan::factory()->create([
        'org_type' => $organization->type,
        'type' => 'package',
        'app_plans' => ['demo_app' => ['max' => $max, 'plans' => []]],
        'settings' => ['standard' => ['max' => 1000]],
    ]);
}

beforeEach(function () {
    $this->organization = Organization::find(1);
});

it('lists a plan the usage would break as unavailable instead of hiding it', function () {
    $too_small = planWithDemoAppMax($this->organization, 0);
    $big_enough = planWithDemoAppMax($this->organization, 5);

    $plans = collect($this->get("/subscription/{$this->organization->id}/options")->viewData('page')['props']['plans'])->keyBy('id');

    expect($plans[$too_small->id]['unavailable'])->toBeTrue();
    expect($plans[$big_enough->id]['unavailable'])->toBeFalse();
});

it('judges every plan on its own limits', function () {
    $too_small = planWithDemoAppMax($this->organization, 0);
    $big_enough = planWithDemoAppMax($this->organization, 5);

    // Whichever plan is evaluated first must not decide the answer for the others
    foreach ([[$big_enough, $too_small], [$too_small, $big_enough]] as $order) {
        cache()->flush();
        $answers = [];
        foreach ($order as $plan) {
            $answers[$plan->id] = (new BasePlanService($this->organization, $plan))->isAnyMaxBroken();
        }

        expect($answers[$too_small->id])->toBeTrue();
        expect($answers[$big_enough->id])->toBeFalse();
    }
});

it('blocks reviewing and selecting a plan the usage would break', function () {
    $too_small = planWithDemoAppMax($this->organization, 0);

    $this->get("/subscription/{$this->organization->id}/plans/{$too_small->id}")->assertForbidden();
    $this->post("/subscription/{$this->organization->id}/plans/{$too_small->id}")->assertForbidden();

    expect($this->organization->refresh()->plan_id)->not->toBe($too_small->id);
});

it('lets a plan the usage fits be reviewed', function () {
    $big_enough = planWithDemoAppMax($this->organization, 5);

    $this->get("/subscription/{$this->organization->id}/plans/{$big_enough->id}")->assertOk();
});

it('names the app and the limit that makes a plan unavailable', function () {
    $plan = planWithDemoAppMax($this->organization, 0);
    $used = $this->demoApp->organization->app_instances()->where('application_id', $this->support->demo_app->id)->count();
    $app = $this->support->demo_app->name;

    $message = (new BasePlanService($this->organization, $plan))->limitsMessage();

    expect($message)->toContain("{$app}: {$used} in use, this plan allows 0");
    expect($message)->not->toContain('users')->not->toContain('storage');

    $listed = collect($this->get("/subscription/{$this->organization->id}/options")->viewData('page')['props']['plans'])->keyBy('id');
    expect($listed[$plan->id]['unavailable_reason'])->toBe($message);

    $this->get("/subscription/{$this->organization->id}/plans/{$plan->id}")
        ->assertForbidden();
});

it('only reports a reason for plans that are unavailable', function () {
    $fits = planWithDemoAppMax($this->organization, 5);

    $listed = collect($this->get("/subscription/{$this->organization->id}/options")->viewData('page')['props']['plans'])->keyBy('id');

    expect($listed[$fits->id]['unavailable_reason'])->toBeNull();
    expect((new BasePlanService($this->organization, $fits))->brokenLimits())->toBe([]);
});

it('has the limit wording in every language', function (string $locale) {
    foreach (['base', 'standard', 'basic', 'storage', 'email', 'application'] as $entity) {
        expect(__("organization.plan.denied.limits.{$entity}", [], $locale))->not->toBe("organization.plan.denied.limits.{$entity}");
    }

    $item = __('organization.plan.denied.limit_item', ['name' => 'Nextcloud', 'used' => 2, 'max' => 1], $locale);
    expect($item)->toContain('Nextcloud')->toContain('2')->toContain('1');
    expect(__('organization.plan.denied.limit', ['limits' => $item], $locale))->toContain($item);
})->with(['en', 'es']);

it('reports an instance with an end date as expiring instead of active', function () {
    $service = fn () => new AppInstancePlanService($this->demoApp->plan, $this->demoApp->fresh());

    expect($service()->status())->toBe('active');

    $this->demoApp->forceFill(['deactivate_at' => now()->addDay()])->save();
    expect($service()->status())->toBe('expiring');

    $this->demoApp->forceFill(['status' => 'deactivating'])->save();
    expect($service()->status())->toBe('deactivating');
});
