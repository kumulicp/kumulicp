<?php

use App\Organization;
use Illuminate\Support\Facades\Gate;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates demo app
// (org 1 already has 1 active demo_app instance; base_1 caps demo_app at max=1),
// creates demo app plans, creates base_2, adds users, and acts as the demo user.
// $this->support, $this->user, $this->demoApp are available.

it('denies activating an app at the plan limit when the organization has no suborganizations', function () {
    expect(Organization::find(1)->suborganizations()->exists())->toBeFalse();

    $response = Gate::inspect('activate-app', $this->support->demo_app);

    expect($response->allowed())->toBeFalse();
});

it('allows activating an app under the plan limit when the organization has no suborganizations', function () {
    $this->demoApp->delete();

    $response = Gate::inspect('activate-app', $this->support->demo_app);

    expect($response->allowed())->toBeTrue();
});

it('checks the plan limit via JointSubscriptionService when the organization has suborganizations', function () {
    $suborg = Organization::factory()->create([
        'plan_id' => $this->support->base_1->id,
        'parent_organization_id' => 1,
        'settings' => ['include_in_parent_invoice' => true],
    ]);

    expect(Organization::find(1)->suborganizations()->exists())->toBeTrue();

    $response = Gate::inspect('activate-app', $this->support->demo_app);

    expect($response->allowed())->toBeFalse();
});
