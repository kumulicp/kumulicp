<?php

/*
|--------------------------------------------------------------------------
| Activating apps for a suborganization
|--------------------------------------------------------------------------
|
| Rules these tests pin down (docs/suborganizations-production-plan.md, section 3):
|
|  - The limits that apply are the target organization's own plan's, not the
|    parent's. Being billed with the parent only decides who pays.
|  - A suborganization billed on its parent's invoice is activated for by the
|    parent. Its own users can do it only when the parent has allowed it.
|  - A suborganization billed separately is activated for only by its own users.
|  - Only the user's own organization and its suborganizations can be the target.
|
| Feature/Subscription's global beforeEach (tests/Pest.php) seeds org 1, which
| already has one active demo app (base_1 caps demo_app at 1), and acts as the
| "demo" user. $this->support, $this->user and $this->demoApp are available.
|
*/

use App\AppPlan;
use App\Organization;
use App\Rules\OrgSuborganization;
use App\Services\Organization\AppActivationContext;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Support\Facades\Gate;

function suborgActivationMakeSuborg($test, array $overrides = []): Organization
{
    $settings = ($overrides['settings'] ?? []) + ['step' => 4, 'include_in_parent_invoice' => true];
    unset($overrides['settings']);

    return Organization::factory()->create($overrides + [
        'plan_id' => $test->support->base_1->id,
        'parent_organization_id' => 1,
        'type' => Organization::find(1)->type,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function suborgActivationUser(Organization $organization): User
{
    return User::factory()->create([
        'organization_id' => $organization->id,
        'email_verified_at' => now(),
    ]);
}

// Limits

it('checks the limit against the target organization own plan, not the parent', function () {
    $suborg = suborgActivationMakeSuborg($this);

    // The parent is at its limit of one demo app
    expect(Gate::inspect('activate-app', $this->support->demo_app)->allowed())->toBeFalse();

    // The suborganization has none, so it can have one
    expect(Gate::inspect('activate-app', [$this->support->demo_app, null, $suborg])->allowed())->toBeTrue();
});

it('denies activating for a target whose own plan does not allow another instance', function () {
    $no_apps = $this->support->base_1->replicate();
    $no_apps->forceFill([
        'name' => 'no apps',
        'is_default' => false,
        'app_plans' => ['demo_app' => ['max' => 0, 'plans' => 'enabled']],
    ])->save();

    $suborg = suborgActivationMakeSuborg($this, ['plan_id' => $no_apps->id]);

    // The parent's plan would allow it once its own demo app is gone
    $this->demoApp->delete();
    expect(Gate::inspect('activate-app', $this->support->demo_app)->allowed())->toBeTrue();

    expect(Gate::inspect('activate-app', [$this->support->demo_app, null, $suborg])->allowed())->toBeFalse();
});

it('does not let a deactivated suborganization be activated for', function () {
    $suborg = suborgActivationMakeSuborg($this, ['status' => 'deactivated']);

    expect(Gate::inspect('activate-app', [$this->support->demo_app, null, $suborg])->allowed())->toBeFalse();
});

// Who may activate

it('lets the parent activate for a suborganization it pays for', function () {
    $suborg = suborgActivationMakeSuborg($this);

    expect((new AppActivationContext($this->user, $suborg))->authorize()->allowed())->toBeTrue();
});

it('does not let the parent activate for a suborganization billed separately', function () {
    $suborg = suborgActivationMakeSuborg($this, ['settings' => ['include_in_parent_invoice' => false]]);

    $response = Gate::inspect('activate-app', [$this->support->demo_app, null, $suborg]);

    expect($response->allowed())->toBeFalse()
        ->and($response->code())->toBe('suborganization_billed_separately');
});

it('does not let a user pick an organization outside their own family', function () {
    $other = Organization::factory()->create([
        'plan_id' => $this->support->base_1->id,
        'type' => Organization::find(1)->type,
        'status' => 'active',
    ]);

    $response = Gate::inspect('activate-app', [$this->support->demo_app, null, $other]);

    expect($response->allowed())->toBeFalse()
        ->and($response->code())->toBe('invalid_target');
});

it('does not let a suborganization user activate when the parent pays and has not allowed it', function () {
    $suborg = suborgActivationMakeSuborg($this);
    $user = suborgActivationUser($suborg);

    $response = Gate::forUser($user)->inspect('activate-app', $this->support->demo_app);

    expect($response->allowed())->toBeFalse()
        ->and($response->code())->toBe('parent_manages');
});

it('lets a suborganization user activate when the parent pays and has allowed it', function () {
    $suborg = suborgActivationMakeSuborg($this, ['settings' => ['allow_app_activation' => true]]);
    $user = suborgActivationUser($suborg);

    expect((new AppActivationContext($user, $suborg))->authorize()->allowed())->toBeTrue();
});

it('lets a user of a separately billed suborganization activate for it', function () {
    $suborg = suborgActivationMakeSuborg($this, ['settings' => ['include_in_parent_invoice' => false]]);
    $user = suborgActivationUser($suborg);

    expect((new AppActivationContext($user, $suborg))->authorize()->allowed())->toBeTrue();
});

it('does not let a suborganization user activate for a sibling', function () {
    $suborg = suborgActivationMakeSuborg($this, ['settings' => ['allow_app_activation' => true]]);
    $sibling = suborgActivationMakeSuborg($this);
    $user = suborgActivationUser($suborg);

    $response = Gate::forUser($user)->inspect('activate-app', [$this->support->demo_app, null, $sibling]);

    expect($response->allowed())->toBeFalse()
        ->and($response->code())->toBe('invalid_target');
});

// Who pays

it('has the parent pay for a suborganization billed with it', function () {
    $suborg = suborgActivationMakeSuborg($this);

    $by_parent = new AppActivationContext($this->user, $suborg);
    expect($by_parent->payer()->id)->toBe(1)
        ->and($by_parent->payerIsUsersOrganization())->toBeTrue();

    // A suborganization's own user is not who pays, so isn't asked for a payment method
    $by_suborg_user = new AppActivationContext(suborgActivationUser($suborg), $suborg);
    expect($by_suborg_user->payer()->id)->toBe(1)
        ->and($by_suborg_user->payerIsUsersOrganization())->toBeFalse();
});

it('has a suborganization billed separately pay for itself', function () {
    $suborg = suborgActivationMakeSuborg($this, ['settings' => ['include_in_parent_invoice' => false]]);

    $context = new AppActivationContext(suborgActivationUser($suborg), $suborg);

    expect($context->payer()->id)->toBe($suborg->id)
        ->and($context->payerIsUsersOrganization())->toBeTrue();
});

// The activate endpoint

it('rejects activating for a suborganization billed separately from the parent', function () {
    $suborg = suborgActivationMakeSuborg($this, ['settings' => ['include_in_parent_invoice' => false]]);
    $plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();

    $this->post('/discover/'.$this->support->demo_app->slug.'/plans/'.$plan->id.'/activate', [
        'organization' => (string) $suborg->id,
        'label' => 'Demo',
        'domain' => 'base',
    ])->assertForbidden();

    expect($suborg->app_instances()->count())->toBe(0);
});

/**
 * A suborganization on a plan that allows no instances of the demo app, with the demo app's plan selectable
 * by it and by the parent (whose own demo app is removed, so the parent could still activate one).
 */
function suborgActivationAtAppLimit($test): Organization
{
    $demo_plan = AppPlan::where('application_id', $test->support->demo_app->id)->firstOrFail();

    $test->support->base_1->forceFill(['app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$demo_plan->id]]]])->save();

    $no_apps = $test->support->base_1->replicate();
    $no_apps->forceFill([
        'name' => 'no apps',
        'is_default' => false,
        'app_plans' => ['demo_app' => ['max' => 0, 'plans' => [$demo_plan->id]]],
    ])->save();

    $test->demoApp->delete();

    return suborgActivationMakeSuborg($test, ['plan_id' => $no_apps->id]);
}

it('answers a suborganization that has reached its app limit with a message for the organization field', function () {
    $suborg = suborgActivationAtAppLimit($this);
    $plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();

    $this->post('/discover/'.$this->support->demo_app->slug.'/plans/'.$plan->id.'/activate', [
        'organization' => (string) $suborg->id,
        'label' => 'Demo',
        'domain' => 'base',
    ])->assertSessionHasErrors(['organization' => __('organization.app.denied.org_limit', ['app' => $this->support->demo_app->name])]);

    expect($suborg->app_instances()->count())->toBe(0);
});

it('offers the choice of organization on the app page, with one at its app limit disabled and the reason beside it', function () {
    $suborg = suborgActivationAtAppLimit($this);
    $ready = suborgActivationMakeSuborg($this);

    $this->get('/discover/'.$this->support->demo_app->slug)
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Discover/DiscoverApp')
            ->where('can.choose_organization', true)
            ->where('organizations', function ($organizations) use ($suborg, $ready) {
                $organizations = collect($organizations)->keyBy('id');

                return $organizations[1]['disabled'] === false
                    && $organizations[$ready->id]['disabled'] === false
                    && $organizations[$suborg->id]['disabled'] === true
                    && $organizations[$suborg->id]['note'] === __('organization.app.denied.org_limit', ['app' => $this->support->demo_app->name]);
            })
        );
});

it('says which organization the app is for on the app page when only one can take it', function () {
    $demo_plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();
    $this->support->base_1->forceFill(['app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$demo_plan->id]]]])->save();
    $suborg = suborgActivationMakeSuborg($this);

    // The parent has its demo app, so only the suborganization can take another
    $this->get('/discover/'.$this->support->demo_app->slug)
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Discover/DiscoverApp')
            ->where('can.choose_organization', false)
            ->where('can.has_suborganizations', true)
            ->where('target.id', $suborg->id)
            ->where('target.name', $suborg->name)
        );
});

it('does not mention an organization on the app page when there are no suborganizations', function () {
    $this->demoApp->delete();

    $this->get('/discover/'.$this->support->demo_app->slug)
        ->assertInertia(fn ($page) => $page
            ->where('can.choose_organization', false)
            ->where('can.has_suborganizations', false)
        );
});

it('says which organization the plans on the plans page are for', function () {
    $demo_plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();
    $second_plan = AppPlan::factory()->create(['application_id' => $this->support->demo_app->id]);
    $this->support->base_1->forceFill(['app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$demo_plan->id, $second_plan->id]]]])->save();
    $suborg = suborgActivationMakeSuborg($this);

    $this->get('/discover/'.$this->support->demo_app->slug.'/plans?organization='.$suborg->id)
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Discover/DiscoverPlans')
            ->where('app.name', $this->support->demo_app->name)
            ->where('has_suborganizations', true)
            ->where('organization.id', $suborg->id)
            ->where('organization.name', $suborg->name)
        );
});

it('does not block the parent at its own limit while a suborganization can still take the app', function () {
    $demo_plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();
    $this->support->base_1->forceFill(['app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$demo_plan->id]]]])->save();
    suborgActivationMakeSuborg($this);

    // The parent has its demo app, so is at its limit of one
    expect(Gate::inspect('activate-app', $this->support->demo_app)->allowed())->toBeTrue()
        // Asked about the parent itself, it is at its limit
        ->and(Gate::inspect('activate-app', [$this->support->demo_app, null, Organization::find(1)])->code())->toBe('limit_reached');
});

it('counts only the instances of the organization asked about', function () {
    $suborg = suborgActivationMakeSuborg($this);

    // The parent's demo app is counted for the parent whichever organization was asked about first
    expect(count(Application::instances($suborg, 'demo_app')))->toBe(0)
        ->and(count(Application::instances(Organization::find(1), 'demo_app')))->toBe(1)
        ->and(count(Application::instances($suborg, 'demo_app')))->toBe(0);
});

it('blocks the parent once its suborganizations are at their limits too', function () {
    $demo_plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();
    $this->support->base_1->forceFill(['app_plans' => ['demo_app' => ['max' => 1, 'plans' => [$demo_plan->id]]]])->save();

    $no_apps = $this->support->base_1->replicate();
    $no_apps->forceFill([
        'name' => 'no apps',
        'is_default' => false,
        'app_plans' => ['demo_app' => ['max' => 0, 'plans' => [$demo_plan->id]]],
    ])->save();
    suborgActivationMakeSuborg($this, ['plan_id' => $no_apps->id]);

    $response = Gate::inspect('activate-app', $this->support->demo_app);

    expect($response->allowed())->toBeFalse()
        ->and($response->code())->toBe('limit_reached');
});

it('lists the plans of the organization chosen, and keeps it through to the review', function () {
    $demo_plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();
    $suborg = suborgActivationAtAppLimit($this);
    $ready = suborgActivationMakeSuborg($this);

    // The suborganization's own plan is the one that decides what it can pick from
    $own_plan = $this->support->base_1->replicate();
    $own_plan->forceFill(['name' => 'no plans', 'is_default' => false, 'app_plans' => ['demo_app' => ['max' => 1, 'plans' => []]]])->save();
    $ready->forceFill(['plan_id' => $own_plan->id])->save();

    expect(Application::availablePlans($this->support->demo_app, Organization::find(1))->pluck('id')->all())->toBe([$demo_plan->id])
        ->and(Application::availablePlans($this->support->demo_app, $ready)->pluck('id')->all())->toBe([]);

    // The only plan of the parent leads on to the review, for the organization asked about when it isn't the user's own
    $this->get('/discover/'.$this->support->demo_app->slug.'/plans')
        ->assertRedirect('/discover/'.$this->support->demo_app->slug.'/plans/'.$demo_plan->id.'/review');
});

it('says which suborganization an app is being activated for on the review', function () {
    $demo_plan = AppPlan::where('application_id', $this->support->demo_app->id)->firstOrFail();
    $suborg = suborgActivationAtAppLimit($this);
    $ready = suborgActivationMakeSuborg($this);

    $this->get('/discover/'.$this->support->demo_app->slug.'/plans/'.$demo_plan->id.'/review?organization='.$ready->id)
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Discover/DiscoverOverview')
            ->where('organization.id', $ready->id)
            ->where('organization.is_suborganization', true)
            ->missing('organizations')
        );

    // An organization at its limit can't be activated for, even when asked for by id
    $this->get('/discover/'.$this->support->demo_app->slug.'/plans/'.$demo_plan->id.'/review?organization='.$suborg->id)
        ->assertForbidden();
});

// The organization rule

it('accepts the parent or one of its active suborganizations as an id sent as a string', function () {
    $suborg = suborgActivationMakeSuborg($this);
    $rule = new OrgSuborganization;

    expect($rule->passes('organization', '1'))->toBeTrue()
        ->and($rule->passes('organization', (string) $suborg->id))->toBeTrue();
});

it('rejects a deactivated suborganization or an unrelated organization as the target', function () {
    $deactivated = suborgActivationMakeSuborg($this, ['status' => 'deactivated']);
    $other = Organization::factory()->create(['plan_id' => $this->support->base_1->id]);
    $rule = new OrgSuborganization;

    expect($rule->passes('organization', (string) $deactivated->id))->toBeFalse()
        ->and($rule->passes('organization', (string) $other->id))->toBeFalse();
});
