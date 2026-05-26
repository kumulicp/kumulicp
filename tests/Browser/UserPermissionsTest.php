<?php

use App\AppPlan;
use App\User;
use Tests\Support\TestSupports;

describe('User Permissions Update', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $support = new TestSupports;
        $support->activateDemoApp();
        $support->addUsers();
        $this->demoInstance = $support->demo_app->instances()->where('organization_id', 1)->first();
    });

    afterEach(function () {
        (new TestSupports)->cleanLdap();
    });

    it('assigns a standard role, shows the access-type chip, and presents the confirm modal', function () {
        $id = $this->demoInstance->id;

        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions')
            ->assertSee('test user1')
            ->assertSee('Demo App')
            ->assertSee('Update Permissions');

        $page->click("#open-permissions-{$id}")
            ->click('#switch-0-demo_role')
            ->click('#modal-ok')
            ->assertSee('Demo Group Demo Role');

        $page->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->assertSee('Access changed');

        $page->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    it('assigns a basic role and the confirm modal lists the correct access change', function () {
        $plan = AppPlan::factory()->create([
            'application_id' => $this->demoInstance->application_id,
            'settings' => [
                'basic' => ['max' => 2, 'name' => 'Basic Member', 'price' => 0, 'amount' => 0, 'storage' => 0, 'price_id' => null],
                'standard' => ['max' => 2, 'price' => 0, 'storage' => 0, 'price_id' => null],
            ],
        ]);
        $this->demoInstance->plan_id = $plan->id;
        $this->demoInstance->save();

        $id = $this->demoInstance->id;

        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click("#open-permissions-{$id}");
        $page->assertSee('Basic Member');
        $page->click('#switch-0-basic_demo_role');
        $page->click('#modal-ok');

        $page->assertSee('Basic Demo Role')
            ->click('#submit');

        $page->assertSee('Confirm Permission Changes')
            ->assertSee('Access changed')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    it('grants control panel access and shows the org chip', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions')
            ->assertDontSee('Organization Demo');

        $page->click('#open-permissions-control_panel')
            ->assertSee('Update App')
            ->click('#switch-0-1')
            ->click('#modal-ok')
            ->assertSee('Organization Demo')
            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    it('grants control panel admin access and shows the Allowed chip', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions')
            ->assertSee('Control Panel');

        $page->click('#open-permissions-control_panel_admin');
        $page->click('#switch-0-control_panel_standard');

        $page->click('#modal-ok')
            ->assertSee('Control Panel Allowed')
            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    it('removes an existing role and shows No Access chip', function () {
        $id = $this->demoInstance->id;

        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click("#open-permissions-{$id}");
        $page->click('#switch-0-demo_role');
        $page->click('#modal-ok');

        $page->click("#open-permissions-{$id}");
        $page->click('#switch-0-demo_role');
        $page->click('#modal-ok');

        $page->assertSee('No Access')
            ->click('#submit');
        $page->assertSee('Confirm Permission Changes')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });
});
