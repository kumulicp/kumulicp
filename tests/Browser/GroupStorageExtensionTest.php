<?php

use App\Application;
use App\User;
use Tests\Support\TestSupports;

describe('Group Storage Extension', function () {
    beforeEach(function () {
        $support = new TestSupports;
        $support->activateDemoAppWithStorage(storageAmount: 10, storageMax: 5);

        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);
    });

    afterEach(function () {
        (new TestSupports)->cleanLdap();
    });

    it('shows storage extension fields when the demo app is active with storage plan', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Test Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit')
            ->assertSee('Edit Storage Test Group Group');

        visit('/groups/Storage Test Group/edit')
            ->assertSee('Add Group Storage');
    });

    it('shows a subscription warning when enabling group storage', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Warning Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/Storage Warning Group/edit');
        $page->assertSee('Add Group Storage');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Changing this group storage may affect your subscription price');
    });

    it('shows the storage amount selector after enabling storage', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Select Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/Storage Select Group/edit');
        $page->assertSee('Add Group Storage');
        $page->assertDontSee('Group Storage Amount');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Group Storage Amount');
        $page->assertSee('Changing this group storage may affect your subscription price');
    });

    it('can add group storage and warning disappears after saving', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Add Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/Storage Add Group/edit');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Changing this group storage may affect your subscription price');
        $page->click('#submit');
        $page->assertSee('Edit Storage Add Group Group');

        visit('/groups/Storage Add Group/edit')
            ->assertSee('Add Group Storage')
            ->assertSee('Group Storage Amount')
            ->assertDontSee('Changing this group storage may affect your subscription price');
    });

    it('shows a subscription warning when changing the storage amount', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Change Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/Storage Change Group/edit');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->click('#submit');

        $page = visit('/groups/Storage Change Group/edit');
        $page->assertSee('Storage Change Group');
        $page->click('#demo_additional_storage');
        $page->click('text=20 GB');
        $page->assertSee('Changing this group storage may affect your subscription price');
    });

    it('shows a subscription warning when removing group storage', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Remove Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/Storage Remove Group/edit');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->click('#submit');

        $page = visit('/groups/Storage Remove Group/edit');
        $page->assertSee('Add Group Storage');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Changing this group storage may affect your subscription price');
    });

    it('can remove group storage and it is gone after saving', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Gone Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/Storage Gone Group/edit');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->click('#submit');

        visit('/groups/Storage Gone Group/edit')
            ->assertSee('Group Storage Amount');

        $page = visit('/groups/Storage Gone Group/edit');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->click('#submit');

        visit('/groups/Storage Gone Group/edit')
            ->assertDontSee('Group Storage Amount');
    });

    it('shows max reached message when storage limit is exceeded', function () {
        $demo_app = Application::where('slug', 'demo_app')->first();
        $app_instance = $demo_app->instances()->first();
        $plan = $app_instance->plan;
        $settings = $plan->settings ?? [];
        $settings['storage'] = ['max' => 1, 'price' => 0, 'amount' => 10];
        $plan->settings = $settings;
        $plan->save();

        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'First Storage Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        $page = visit('/groups/First Storage Group/edit');
        $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
        $page->click('#submit');

        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Second Storage Group')
            ->click('#category')
            ->click('text="Team"')
            ->click('#submit');

        visit('/groups/Second Storage Group/edit')
            ->assertSee("You've reached the maximum additional storage for your plan");
    });
});
