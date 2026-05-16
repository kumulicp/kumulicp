<?php

use App\AppPlan;
use App\User;
use Tests\Support\TestSupports;

describe('Shared Apps', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $support = new TestSupports();
        $support->activateDemoApp();

        $this->separatePlan = AppPlan::factory()->create([
            'application_id' => $support->demo_app->id,
            'name' => 'Separate Plan',
            'settings' => [
                'server_type' => 'separate',
                'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
                'basic' => ['max' => 5, 'name' => 'Basic', 'price' => 0, 'amount' => 0, 'storage' => 0, 'price_id' => null],
                'storage' => ['max' => 0, 'price' => 0, 'amount' => 0, 'price_id' => null],
                'standard' => ['max' => 5, 'price' => 0, 'storage' => 0, 'price_id' => null],
            ],
        ]);

        $this->sharedPlan = AppPlan::factory()->create([
            'application_id' => $support->demo_app->id,
            'name' => 'Shared Plan',
            'settings' => [
                'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
                'basic' => ['max' => 5, 'name' => 'Basic', 'price' => 0, 'amount' => 0, 'storage' => 0, 'price_id' => null],
                'storage' => ['max' => 0, 'price' => 0, 'amount' => 0, 'price_id' => null],
                'standard' => ['max' => 5, 'price' => 0, 'storage' => 0, 'price_id' => null],
            ],
        ]);

        $this->demoApp = $support->demo_app;
        $this->support = $support;
    });

    afterEach(function () {
        (new TestSupports())->cleanLdap();
    });

    it('adds a shared app and updates its label', function () {
        $page = visit('/admin/service/shared-apps')
            ->assertSee('Shared Apps')
            ->assertSee('Enable Shared Apps');

        $page->click('text=Enable Shared Apps')
            ->assertPathIs('/admin/service/shared-apps')
            ->assertSee('Add App');

        $page->click('#createApp');
        $page->click('#app');
        $page->click('text=Demo App');
        $page->click('#plan');
        $page->click('text=Separate Plan');
        $page->fill('#label input', 'Shared Demo App');
        $page->click('#submit');

        $page->assertSee('Shared Demo App')
            ->fill('#appLabel input', 'Updated Shared Demo App')
            ->click('#submit')
            ->assertSee('Updated Shared Demo App Settings');
    });

    it('updates a plan to use the shared app', function () {
        $sharedOrg = $this->support->activateSharedApps();
        $sharedInstance = $this->support->addSharedAppInstance(
            $sharedOrg,
            $this->demoApp,
            $this->separatePlan,
            'Shared Demo App'
        );

        $plan = $this->sharedPlan;

        $page = visit("/admin/apps/demo_app/plans/{$plan->id}/edit")
            ->assertSee($plan->name);

        $page->click('#serverType');
        $page->click('text=Connect Shared App');
        $page->click('#sharedApp');
        $page->click('text=Shared Demo App');
        $page->click('#submit');

        $page->assertPathIs("/admin/apps/demo_app/plans/{$plan->id}");

        $plan->refresh();
        expect($plan->setting('server_type'))->toBe('shared');
        expect($plan->shared_app_id)->toBe($sharedInstance->id);
    });
});
