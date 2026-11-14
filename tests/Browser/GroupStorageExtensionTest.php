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

        // The add group form is covered by the Feature tests: this is about the storage fields on the edit page
        $this->createGroup = fn (string $name) => $this->post('/groups', ['name' => $name, 'category' => 'teams']);
        $this->toggleStorage = fn ($page) => $page->script("document.querySelector('#demo_group_storage').closest('.va-checkbox__input-container').click()");
    });

    afterEach(function () {
        (new TestSupports)->cleanLdap();
    });

    it('shows the storage amount and a subscription warning when storage is enabled, until it is saved', function () {
        ($this->createGroup)('Storage Add Group');

        $page = visit('/groups/Storage Add Group/edit')
            ->assertSee('Add Group Storage')
            ->assertDontSee('Group Storage Amount')
            ->assertDontSee('Changing this group storage may affect your subscription price');

        ($this->toggleStorage)($page);
        $page->assertSee('Group Storage Amount')
            ->assertSee('Changing this group storage may affect your subscription price')
            ->click('#submit')
            ->assertSee('Edit Storage Add Group Group');

        visit('/groups/Storage Add Group/edit')
            ->assertSee('Add Group Storage')
            ->assertSee('Group Storage Amount')
            ->assertDontSee('Changing this group storage may affect your subscription price');
    });

    it('shows a subscription warning when changing the storage amount', function () {
        ($this->createGroup)('Storage Change Group');

        $page = visit('/groups/Storage Change Group/edit');
        ($this->toggleStorage)($page);
        $page->click('#submit');

        visit('/groups/Storage Change Group/edit')
            ->assertSee('Storage Change Group')
            ->assertDontSee('Changing this group storage may affect your subscription price')
            ->click('#demo_additional_storage')
            ->click('text=20 GB')
            ->assertSee('Changing this group storage may affect your subscription price');
    });

    it('shows a subscription warning when removing group storage, and the storage is gone once saved', function () {
        ($this->createGroup)('Storage Gone Group');

        $page = visit('/groups/Storage Gone Group/edit');
        ($this->toggleStorage)($page);
        $page->click('#submit');

        $page = visit('/groups/Storage Gone Group/edit')
            ->assertSee('Group Storage Amount');
        ($this->toggleStorage)($page);
        $page->assertSee('Changing this group storage may affect your subscription price')
            ->click('#submit');

        visit('/groups/Storage Gone Group/edit')
            ->assertSee('Add Group Storage')
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

        ($this->createGroup)('First Storage Group');
        $page = visit('/groups/First Storage Group/edit');
        ($this->toggleStorage)($page);
        $page->click('#submit');

        ($this->createGroup)('Second Storage Group');
        visit('/groups/Second Storage Group/edit')
            ->assertSee("You've reached the maximum additional storage for your plan");
    });
});
