<?php

use App\Organization;
use App\Plan;
use App\User;

describe('Admin Plans', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows the plans list and adds a new plan', function () {
        visit('/admin/service/plans')
            ->assertSee('Plans')
            ->click('#addPlan')
            ->fill('#planName input', 'Browser Test Plan')
            ->fill('#planDescription input', 'A plan created by a browser test')
            ->click('#addPlanSubmit')
            ->assertPathIs('/admin/service/plans')
            ->assertSee('Browser Test Plan');
    });

    it('updates a plan name', function () {
        $plan = Plan::factory()->create([
            'name' => 'Original Plan',
            'description' => 'Original description',
            'type' => 'app',
        ]);

        visit("/admin/service/plans/{$plan->id}")
            ->assertSee('Original Plan')
            ->fill('#planName input', 'Renamed Plan')
            ->click('#submit')
            ->assertPathIs('/admin/service/plans')
            ->assertSee('Renamed Plan');
    });

    it('removes a plan with no subscribers', function () {
        $plan = Plan::factory()->create(['name' => 'Plan To Remove']);

        visit("/admin/service/plans/{$plan->id}/remove")
            ->assertPathIs('/admin/service/plans');

        expect(Plan::find($plan->id))->toBeNull();
    });

    it('does not remove a plan with active subscribers', function () {
        $plan = Plan::factory()->create(['name' => 'Subscribed Plan']);

        $org = Organization::find(1);
        $org->plan_id = $plan->id;
        $org->save();

        visit("/admin/service/plans/{$plan->id}/remove")
            ->assertPathIs('/admin/service/plans');

        expect(Plan::find($plan->id))->not->toBeNull();
    });
});
