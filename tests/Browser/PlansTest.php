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
            ->click('text=Package')
            ->click('#addPlanSubmit')
            ->assertPathIs('/admin/service/plans/'.Plan::where('name', 'My Package Plan')->first()?->id);
    });

    it('creates an app plan with type selected in modal', function () {
        visit('/admin/service/plans')
            ->click('#addPlan')
            ->fill('#planName input', 'My App Plan')
            ->fill('#planDescription input', 'An app description')
            ->click('#planType')
            ->click('text=Pay per App')
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
        $page->click('text=Pay per App');
        $page->assertSee('Changing the plan type');
    });

    it('app plan select allows only a single selection', function () {
        $plan = Plan::factory()->create(['type' => 'app', 'name' => 'Single Select Plan', 'org_type' => 'none']);

        $page = visit('/admin/service/plans/'.$plan->id);
        $page->assertSee('App Settings');
        // Verify the select does not have multiple attribute by asserting only one value can be active
        // We check via the DOM that the va-select does not have a 'multiple' attribute
        $hasMultiple = $page->script("
            const selects = document.querySelectorAll('.va-select');
            return Array.from(selects).some(el => el.hasAttribute('multiple'));
        ");
        expect($hasMultiple)->toBeFalsy();
    });
});
