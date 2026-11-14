<?php

use App\Application;
use App\AppPlan;
use App\Plan;
use App\User;
use Tests\Support\TestSupports;

// The plan lists are reordered by dragging rows, which only a browser can do. Saving the order is covered by the Feature tests
describe('Plan Order', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('reorders the plans by dragging a row and saves the new order', function () {
        foreach (['Order Plan A', 'Order Plan B', 'Order Plan C'] as $index => $name) {
            Plan::factory()->create(['name' => $name, 'display_order' => 1000 + $index, 'archive' => false]);
        }

        visit('/admin/service/plans')
            ->assertSee('Order Plan A')
            ->drag('tr:has-text("Order Plan C")', 'tr:has-text("Order Plan A")')
            ->click('button:has-text("Update Order")')
            ->assertPathIs('/admin/service/plans');

        expect(Plan::whereIn('name', ['Order Plan A', 'Order Plan B', 'Order Plan C'])->orderBy('display_order')->pluck('name')->all())
            ->toBe(['Order Plan C', 'Order Plan A', 'Order Plan B']);
    });

    it('reorders the plans of an app by dragging a row and saves the new order', function () {
        (new TestSupports)->activateDemoApp();
        $application = Application::where('slug', 'demo_app')->firstOrFail();

        foreach (['App Order A', 'App Order B', 'App Order C'] as $index => $name) {
            AppPlan::factory()->create([
                'application_id' => $application->id,
                'name' => $name,
                'display_order' => 1000 + $index,
                'archive' => false,
            ]);
        }

        visit('/admin/apps/demo_app/plans')
            ->assertSee('App Order A')
            ->drag('tr:has-text("App Order C")', 'tr:has-text("App Order A")')
            ->click('button:has-text("Update Order")')
            ->assertSee('App Order C');

        expect(AppPlan::whereIn('name', ['App Order A', 'App Order B', 'App Order C'])->orderBy('display_order')->pluck('name')->all())
            ->toBe(['App Order C', 'App Order A', 'App Order B']);
    });
});
