<?php

use App\Plan;
use App\User;

describe('Admin Plans', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows the plans list and add plan modal with type selector', function () {
        visit('/admin/service/plans')
            ->assertSee('Plans')
            ->click('#addPlan')
            ->assertSee('Add Plan')
            ->assertSee('Plan Type');
    });

    it('creates a package plan with type selected in modal', function () {
        visit('/admin/service/plans')
            ->click('#addPlan')
            ->fill('#planName input', 'My Package Plan')
            ->fill('#planDescription input', 'A package description')
            ->click('#planType')
            ->click('[role=option]:text-is("Package")')
            ->click('#addPlanSubmit')
            ->assertPathIs('/admin/service/plans/'.Plan::where('name', 'My Package Plan')->first()?->id);
    });

    it('creates an app plan with type selected in modal', function () {
        visit('/admin/service/plans')
            ->click('#addPlan')
            ->fill('#planName input', 'My App Plan')
            ->fill('#planDescription input', 'An app description')
            ->click('#planType')
            ->click('[role=option]:text-is("Pay per App")')
            ->click('#addPlanSubmit')
            ->assertPathIs('/admin/service/plans/'.Plan::where('name', 'My App Plan')->first()?->id);
    });

    it('shows the plan edit page with type selector and no type change warning initially', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Edit Test Plan', 'org_type' => 'none']);

        visit('/admin/service/plans/'.$plan->id)
            ->assertSee('Edit Edit Test Plan Plan')
            ->assertSee('Plan Type')
            ->assertDontSee('Changing the plan type');
    });

    it('shows a warning when the plan type is changed on edit', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Change Type Plan', 'org_type' => 'none']);

        $page = visit('/admin/service/plans/'.$plan->id);
        $page->assertDontSee('Changing the plan type');
        $page->click('#planType');
        $page->click('[role=option]:text-is("Pay per App")');
        $page->assertSee('Changing the plan type');
    });

    it('hides users and storage sections for app type plans', function () {
        $plan = Plan::factory()->create(['type' => 'app', 'name' => 'App Type Visibility Plan', 'org_type' => 'none']);

        visit('/admin/service/plans/'.$plan->id)
            ->assertSee('Base Options')
            ->assertDontSee('Standard User Options')
            ->assertDontSee('Basic User Options')
            ->assertDontSee('Additional Storage Options');
    });

    it('shows users and storage sections for package type plans', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Package Visibility Plan', 'org_type' => 'none']);

        visit('/admin/service/plans/'.$plan->id)
            ->assertSee('Standard User Options')
            ->assertSee('Basic User Options')
            ->assertSee('Additional Storage Options');
    });

    it('app plan select allows only a single selection', function () {
        $plan = Plan::factory()->create(['type' => 'app', 'name' => 'Single Select Plan', 'org_type' => 'none']);

        $page = visit('/admin/service/plans/'.$plan->id);
        $page->assertSee('App Settings');
        $hasMultiple = $page->script("Array.from(document.querySelectorAll('.va-select')).some(el => el.hasAttribute('multiple'))");
        expect($hasMultiple)->toBeFalsy();
    });
});
