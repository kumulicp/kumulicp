<?php

use App\Application;
use App\AppPlan;
use App\Enums\PlanEntity;
use App\Organization;
use App\Services\Organization\BasePlanService;
use App\Services\Organization\SingleTenantBasePlanService;
use App\Services\SubscriptionService;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Gate;
use OffloadProject\Toggle\Facades\Toggle;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates the demo
// app, creates its plans, and acts as the demo user. base_1 caps demo_app at
// one instance and standard users at ten, and the demo app already has one
// instance, so a plan that was still applied would show up here.

beforeEach(function () {
    $this->support->demo_app->enabled = true;
    $this->support->demo_app->save();
});

function makeSingleOrganization(): Organization
{
    useSingleTenancy();

    $organization = Organization::find(1);
    $organization->type = 'superaccount';
    $organization->save();

    return $organization;
}

it('gives the single organization an unlimited base plan', function () {
    $organization = makeSingleOrganization();

    $base = (new SubscriptionService($organization))->base();

    expect($base)->toBeInstanceOf(SingleTenantBasePlanService::class);
});

it('has domains and email on whatever the stored plan says', function () {
    $organization = makeSingleOrganization();
    $organization->plan->update(['domain_enabled' => false, 'email_enabled' => false]);

    $base = (new SubscriptionService($organization->fresh()))->base();

    expect($base->domainsEnabled())->toBeTrue();
    expect($base->emailEnabled())->toBeTrue();
    expect($base->setting('domains.connect'))->toBeTrue();
    expect($base->isDomainMax())->toBeFalse();
});

it('keeps the ordinary base plan outside single mode', function () {
    $organization = Organization::find(1);
    $organization->type = 'superaccount';
    $organization->save();

    expect((new SubscriptionService($organization))->base())->not->toBeInstanceOf(SingleTenantBasePlanService::class);
});

it('keeps the ordinary base plan for any other organization in single mode', function () {
    useSingleTenancy();
    $organization = Organization::find(1);
    $organization->type = 'business';
    $organization->save();

    expect((new SubscriptionService($organization))->base())->not->toBeInstanceOf(SingleTenantBasePlanService::class);
});

it('enables every app even when the plan lists none', function () {
    $organization = makeSingleOrganization();
    $organization->plan->app_plans = [];
    $organization->plan->save();
    $base = (new SubscriptionService($organization->refresh()))->base();

    expect($base->appEnabled($this->support->demo_app))->toBeTrue();
    expect($base->enabledApps()->pluck('slug'))->toContain('demo_app');
});

it('offers the visible plans of an app and not hidden ones', function () {
    $organization = makeSingleOrganization();
    $hidden = AppPlan::factory()->create([
        'application_id' => $this->support->demo_app->id,
        'hidden' => true,
    ]);
    $base = (new SubscriptionService($organization))->base();

    expect($base->appPlansList($this->support->demo_app))
        ->toContain($this->support->demo_app_1->id)
        ->not->toContain($hidden->id);
    expect($base->appPlanEnabled($hidden))->toBeFalse();
    expect($base->appPlans($this->support->demo_app, archived: false)->pluck('id'))->toContain($this->support->demo_app_2->id);
});

it('has no limits', function () {
    $organization = makeSingleOrganization();
    $base = (new SubscriptionService($organization))->base();

    expect($base->isMaxApps($this->support->demo_app))->toBeFalse();
    expect($base->isMaxAppsBroken())->toBeFalse();
    expect($base->isAnyMaxBroken())->toBeFalse();
    expect($base->isDomainMax())->toBeFalse();
    expect($base->appMax($this->support->demo_app))->toBeNull();

    foreach (['standard', 'basic', 'storage', 'email', 'application'] as $entity) {
        expect($base->isMax($entity))->toBeFalse();
    }
    foreach (PlanEntity::cases() as $entity) {
        expect($base->isMaxBroken($entity))->toBeFalse();
    }
});

it('contrasts with the ordinary plan, which is limited', function () {
    $organization = Organization::find(1);
    $base = new BasePlanService($organization, $this->support->base_1);

    expect($base->isMaxApps($this->support->demo_app))->toBeTrue();
});

it('lets the single organization view, activate and pick plans for any app', function () {
    $organization = makeSingleOrganization();
    $organization->plan->app_plans = [];
    $organization->plan->save();
    Subscription::refresh();
    $demo_app = $this->support->demo_app;

    expect(Gate::allows('view-app', $demo_app))->toBeTrue();
    expect(Gate::allows('select-app-plan', $this->support->demo_app_1))->toBeTrue();
    expect(Gate::inspect('activate-app', $demo_app)->code())->not->toBe('plan_limit_reached');
    expect(Gate::inspect('activate-app', $demo_app)->code())->not->toBe('limit_reached');
});

it('still refuses an archived or hidden app plan to the single organization', function () {
    makeSingleOrganization();
    Subscription::refresh();
    $archived = AppPlan::factory()->create(['application_id' => $this->support->demo_app->id, 'archive' => true]);
    $hidden = AppPlan::factory()->create(['application_id' => $this->support->demo_app->id, 'hidden' => true]);

    expect(Gate::allows('select-app-plan', $archived))->toBeFalse();
    expect(Gate::allows('select-app-plan', $hidden))->toBeFalse();
});

// An app instance always counts as one "base", so a plan that caps base at 1 is at
// its limit however many users there are
function appPlanCappedAtOne(): AppPlan
{
    return AppPlan::factory()->create([
        'application_id' => Application::where('slug', 'demo_app')->firstOrFail()->id,
        'settings' => ['base' => ['max' => 1]],
    ]);
}

it('does not let an app plan limit the single organization', function () {
    makeSingleOrganization();
    Subscription::refresh();
    $app_instance = Application::where('slug', 'demo_app')->firstOrFail()->instances()->first();

    $plan = Subscription::appInstanceSubscription($app_instance, appPlanCappedAtOne());

    expect($plan->isMax('base'))->toBeFalse();
    expect($plan->isAnyMaxBroken())->toBeFalse();
});

it('still applies app plan limits in multi-tenant mode', function () {
    useMultiTenancy();
    $app_instance = Application::where('slug', 'demo_app')->firstOrFail()->instances()->first();

    $plan = Subscription::appInstanceSubscription($app_instance, appPlanCappedAtOne());

    expect($plan->isMax('base'))->toBeTrue();
});

it('lets the multi-tenant gate still require the plan to list the app plan', function () {
    $this->support->base_1->app_plans = ['demo_app' => ['max' => 1, 'plans' => [$this->support->demo_app_1->id]]];
    $this->support->base_1->save();
    Subscription::refresh();

    expect(Gate::allows('select-app-plan', $this->support->demo_app_1))->toBeTrue();
    expect(Gate::allows('select-app-plan', $this->support->demo_app_2))->toBeFalse();
});

it('offers HelmForge apps only while the HelmForge feature is on', function () {
    $organization = makeSingleOrganization();
    Application::factory()->create(['slug' => 'from_helmforge', 'source' => 'helmforge', 'enabled' => true]);
    $base = fn () => (new SubscriptionService($organization))->base()->enabledApps()->pluck('slug');

    expect($base())->not->toContain('from_helmforge');

    Toggle::enable('helmforge');
    expect($base())->toContain('from_helmforge');
});
