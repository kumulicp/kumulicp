<?php

use App\Organization;
use App\Plan;
use App\User;

describe('Change Base Plan', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $this->organization = Organization::find(1);

        // allAvailable() filters by org_type = organization->type ('superaccount').
        // Update the seeded base plan so it counts among available plans.
        $this->organization->plan->update(['org_type' => 'superaccount']);
    });

    it('does not show the Change Plan button when only one plan is available', function () {
        // Only the current plan exists with a matching org_type: count = 1, not > 1
        visit('/subscription/plans')
            ->assertDontSee('Change Plan');
    });

    it('shows the Change Plan button after adding a second plan', function () {
        Plan::factory()->create([
            'name' => 'Premium Plan',
            'org_type' => 'superaccount',
            'payment_enabled' => false,
            'archive' => false,
        ]);

        // Now two plans have a matching org_type: count = 2 > 1
        visit('/subscription/plans')
            ->assertSee('Change Plan');
    });

    it('can click Change Plan, select the new plan, and complete the plan change', function () {
        $newPlan = Plan::factory()->create([
            'name' => 'Premium Plan',
            'description' => 'Premium Plan',
            'org_type' => 'superaccount',
            'payment_enabled' => false,
            'archive' => false,
        ]);

        $orgId = $this->organization->id;

        visit('/subscription/plans')
            ->assertSee('Change Plan')
            ->click('button:has-text("Change Plan")')
            ->assertPathIs('/subscription/'.$orgId.'/options')
            ->assertSee('Premium Plan')
            ->click('#select'.$newPlan->id)
            ->assertPathIs('/subscription/'.$orgId.'/plans/'.$newPlan->id)
            ->click('button:has-text("Subscribe")')
            ->assertPathIs('/subscription/'.$orgId.'/options')
            ->assertSee('Premium Plan');
    });
});
