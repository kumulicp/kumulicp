<?php

use App\Application;
use App\AppPlan;
use App\Plan;
use App\User;

// The enabled apps list on the plan edit page: apps are added, edited and removed in a modal and only
// saved with the rest of the plan. What is saved is covered by Feature/Admin/PlansTest
describe('Plan App Plans', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());

        $this->appOne = Application::factory()->create(['slug' => 'plan_apps_one', 'name' => 'Plan Apps One', 'enabled' => true]);
        $this->appTwo = Application::factory()->create(['slug' => 'plan_apps_two', 'name' => 'Plan Apps Two', 'enabled' => true]);
        $this->small = AppPlan::factory()->create(['application_id' => $this->appOne->id, 'name' => 'Small App Plan']);
        $this->large = AppPlan::factory()->create(['application_id' => $this->appOne->id, 'name' => 'Large App Plan']);
        $this->otherPlan = AppPlan::factory()->create(['application_id' => $this->appTwo->id, 'name' => 'Other App Plan']);

        $this->pickOption = fn ($page, string $select, string $option) => $page
            ->click($select)
            ->click("[role=option]:text-is(\"{$option}\")");
    });

    it('adds an app with several app plans to a pay per app plan, and offers it no more once added', function () {
        $plan = Plan::factory()->create(['type' => 'app', 'name' => 'Pay Per App Plan', 'org_type' => 'none']);

        $page = visit("/admin/service/plans/{$plan->id}")
            ->assertPresent('#noApps')
            ->click('#addApp');

        // Plans can only be picked once an app is, and are the ones of that app
        $page->assertDisabled('#saveApp');
        ($this->pickOption)($page, '#appModalApp', 'Plan Apps One');
        // The dropdown stays open after an option is picked of a multiple select
        ($this->pickOption)($page, '#appModalPlan', 'Small App Plan');
        $page->click('[role=option]:text-is("Large App Plan")')
            ->click('#appModalMax input')
            ->assertDontSee('Other App Plan')
            ->fill('#appModalMax input', '3')
            ->click('#saveApp')
            ->assertPresent('#app-row-plan_apps_one')
            ->assertNotPresent('#noApps')
            ->assertSee('Small App Plan, Large App Plan')
            ->assertSee('Max Activations: 3');

        // Nothing is stored until the plan is saved
        expect($plan->refresh()->app_plans['plan_apps_one']['plans'] ?? [])->toBeEmpty();

        $page->click('#submit')
            ->assertPathIs('/admin/service/plans');

        $saved = $plan->refresh()->app_plans['plan_apps_one'];
        expect($saved['plans'])->toEqualCanonicalizing([$this->small->id, $this->large->id]);
        expect((int) $saved['max'])->toBe(3);
    });

    it('edits the app plans and activations of an added app', function () {
        $plan = Plan::factory()->create([
            'type' => 'app',
            'name' => 'Edit Apps Plan',
            'org_type' => 'none',
            'app_plans' => ['plan_apps_one' => ['max' => 1, 'plans' => [$this->small->id]]],
        ]);

        $page = visit("/admin/service/plans/{$plan->id}")
            ->assertSee('Small App Plan')
            ->click('#edit-app-plan_apps_one')
            ->assertSee('Edit App');

        // The app is the one being edited and can't be swapped for another
        $page->assertDisabled('#appModalApp');
        ($this->pickOption)($page, '#appModalPlan', 'Large App Plan');
        $page->click('#appModalMax input')
            ->fill('#appModalMax input', '7')
            ->click('#saveApp')
            ->assertSee('Small App Plan, Large App Plan')
            ->assertSee('Max Activations: 7')
            ->click('#submit')
            ->assertPathIs('/admin/service/plans');

        $saved = $plan->refresh()->app_plans['plan_apps_one'];
        expect($saved['plans'])->toEqualCanonicalizing([$this->small->id, $this->large->id]);
        expect((int) $saved['max'])->toBe(7);
    });

    it('removes an app from the plan, which then offers it again', function () {
        $plan = Plan::factory()->create([
            'type' => 'app',
            'name' => 'Remove Apps Plan',
            'org_type' => 'none',
            'app_plans' => [
                'plan_apps_one' => ['max' => 1, 'plans' => [$this->small->id]],
                'plan_apps_two' => ['max' => 1, 'plans' => [$this->otherPlan->id]],
            ],
        ]);

        $page = visit("/admin/service/plans/{$plan->id}")
            ->assertPresent('#app-row-plan_apps_one')
            ->assertPresent('#app-row-plan_apps_two')
            // Every app is on the plan already: nothing left to add
            ->assertDisabled('#addApp')
            ->click('#remove-app-plan_apps_one')
            ->assertNotPresent('#app-row-plan_apps_one')
            ->assertPresent('#app-row-plan_apps_two')
            ->assertEnabled('#addApp')
            ->click('#addApp');

        $page->click('#appModalApp')
            ->assertSee('Plan Apps One');

        $page->click('text=Cancel')
            ->click('#submit')
            ->assertPathIs('/admin/service/plans');

        $apps = $plan->refresh()->app_plans;
        expect($apps['plan_apps_one']['plans'] ?? [])->toBeEmpty();
        expect($apps['plan_apps_two']['plans'])->toBe([$this->otherPlan->id]);
    });

    it('lets a package plan pick a single app plan per app', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Package Apps Plan', 'org_type' => 'none']);

        $page = visit("/admin/service/plans/{$plan->id}")
            ->click('#addApp');

        ($this->pickOption)($page, '#appModalApp', 'Plan Apps One');
        ($this->pickOption)($page, '#appModalPlan', 'Large App Plan');
        $page->click('#saveApp')
            ->assertSee('Large App Plan')
            ->assertDontSee('Small App Plan')
            ->click('#submit')
            ->assertPathIs('/admin/service/plans');

        expect($plan->refresh()->app_plans['plan_apps_one']['plans'])->toBe([$this->large->id]);
    });
});
