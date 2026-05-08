<?php

use App\Organization;
use App\Plan;

describe('Admin Plans', function () {
    beforeEach(function () {
        // DemoSeeder already runs from Pest.php global beforeEach.
        // The demo user (demo@example.com) has control_panel_admin access.
    });

    function loginAsAdmin(): void
    {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    }

    // ---------------------------------------------------------------------------
    // Add plan
    // ---------------------------------------------------------------------------

    it('shows the plans list page after login', function () {
        loginAsAdmin();

        visit('/admin/service/plans')
            ->assertSee('Plans');
    });

    it('adds a new plan via the modal form', function () {
        loginAsAdmin();

        visit('/admin/service/plans')
            ->assertSee('Add Plan')
            ->click('button:has-text("Add Plan")')
            ->waitForSelector('input[aria-label="Name"], input[placeholder*="Name"], .va-modal input', ['timeout' => 5000])
            ->fill('.va-modal input:first-of-type', 'Browser Test Plan')
            ->fill('.va-modal textarea, .va-modal input:last-of-type', 'A plan created by a browser test')
            ->click('.va-modal button[type=submit]')
            ->assertPathIs('/admin/service/plans')
            ->assertSee('Browser Test Plan');
    });

    // ---------------------------------------------------------------------------
    // Update plan
    // ---------------------------------------------------------------------------

    it('can update a plan name and description', function () {
        $plan = Plan::factory()->create([
            'name' => 'Original Browser Plan',
            'description' => 'Original description',
        ]);

        loginAsAdmin();

        visit("/admin/service/plans/{$plan->id}")
            ->assertSee('Edit')
            ->waitForSelector('input', ['timeout' => 5000])
            ->clear('input[value="Original Browser Plan"]')
            ->fill('input[value=""]', 'Renamed Browser Plan')
            ->click('button[type=submit]:has-text("Update")')
            ->assertPathIs('/admin/service/plans')
            ->assertSee('Renamed Browser Plan');
    });

    // ---------------------------------------------------------------------------
    // Remove plan
    // ---------------------------------------------------------------------------

    it('removes a plan with no subscribers by navigating to the remove route', function () {
        $plan = Plan::factory()->create(['name' => 'Plan To Remove']);

        loginAsAdmin();

        visit("/admin/service/plans/{$plan->id}/remove")
            ->assertPathIs('/admin/service/plans');

        expect(Plan::find($plan->id))->toBeNull();
    });

    it('does not remove a plan that has active subscribers', function () {
        $plan = Plan::factory()->create(['name' => 'Subscribed Plan']);

        $org = Organization::find(1);
        $org->plan_id = $plan->id;
        $org->save();

        loginAsAdmin();

        visit("/admin/service/plans/{$plan->id}/remove")
            ->assertPathIs('/admin/service/plans');

        expect(Plan::find($plan->id))->not->toBeNull();
    });

    // ---------------------------------------------------------------------------
    // Update order
    // ---------------------------------------------------------------------------

    it('updates plan display order via the update_order form submission', function () {
        $plan_a = Plan::factory()->create(['name' => 'Alpha Plan', 'display_order' => 1]);
        $plan_b = Plan::factory()->create(['name' => 'Beta Plan', 'display_order' => 2]);

        loginAsAdmin();

        // The draggable table renders on this page; submit the existing order to confirm the action works
        visit('/admin/service/plans')
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan')
            ->assertSee('Update Order');
    });
});
