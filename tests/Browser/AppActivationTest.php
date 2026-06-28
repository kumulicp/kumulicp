<?php

use App\Plan;
use App\User;
use Tests\Support\TestSupports;

describe('App Activation', function () {
    beforeEach(function () {
        $support = new TestSupports();
        ['app' => $application, 'plan' => $plan] = $support->prepareDemoApp();

        $application->enabled = true;
        $application->domain_option = ['base'];
        $application->save();

        // Make the plan discoverable by referencing its ID in the org's base plan
        $basePlan = Plan::where('is_default', true)->firstOrFail();
        $basePlan->app_plans = array_merge($basePlan->app_plans ?? [], [
            'demo_app' => ['max' => 1, 'plans' => [$plan->id]],
        ]);
        $basePlan->save();

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('starts on the apps page, navigates through discover, and shows the activated app in the apps list', function () {
        $page = visit('/apps')
            ->assertPathIs('/discover')
            ->assertSee('Demo App');

        $page->click('#view-demo_app')
            ->assertPathIs('/discover/demo_app')
            ->assertSee('Select');

        $page->click('#select-plan')
            ->assertSee('Demo App')
            ->assertSee('Activate');

        $page->click('#activate')
            ->assertPathIs('/apps')
            ->assertSee('Demo App');
    });
});
