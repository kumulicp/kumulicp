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

    it('shows storage extension fields when the demo app is active with storage plan', function () {
        // Create a group first
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Test Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit')
            ->assertSee('Edit Storage Test Group Group');

        // The storage checkbox should be visible and unchecked (no storage allocated yet)
        visit('/groups/storage-test-group/edit')
            ->assertSee('Add Group Storage');
    });

    it('shows a subscription warning when enabling group storage', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Warning Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/storage-warning-group/edit')
            ->assertSee('Add Group Storage')
            // Enable the storage checkbox — form value differs from saved value (false), warning appears
            ->click('text=Add Group Storage')
            ->assertSee('Changing this group storage may affect your subscription price');
    });

    it('shows the storage amount selector after enabling storage', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Select Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/storage-select-group/edit')
            ->assertSee('Add Group Storage')
            // Before enabling storage, the amount select should not be visible
            ->assertDontSee('Group Storage Amount')
            // Enable the checkbox
            ->click('text=Add Group Storage')
            // Now the storage amount select should appear (conditional rendering)
            ->assertSee('Group Storage Amount')
            ->assertSee('Changing this group storage may affect your subscription price');
    });

    it('can add group storage and warning disappears after saving', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Add Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        // Enable storage and save
        visit('/groups/storage-add-group/edit')
            ->click('text=Add Group Storage')
            ->assertSee('Changing this group storage may affect your subscription price')
            ->click('#submit')
            ->assertSee('Edit Storage Add Group Group');

        // After saving, reload the page — checkbox is checked, no warning (form value matches saved value)
        visit('/groups/storage-add-group/edit')
            ->assertSee('Add Group Storage')
            ->assertSee('Group Storage Amount')
            // Warning should not show since form value now matches the saved value
            ->assertDontSee('Changing this group storage may affect your subscription price');
    });

    it('shows a subscription warning when changing the storage amount', function () {
        // First create a group and add storage
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Change Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/storage-change-group/edit')
            ->click('text=Add Group Storage')
            ->click('#submit');

        // Now go back, see the select, change to a different value → warning appears
        visit('/groups/storage-change-group/edit')
            ->assertSee('Group Storage Amount')
            // The current saved value (1) is shown — changing the select triggers the warning
            ->click('#demo_additional_storage')
            ->assertSee('Changing this group storage may affect your subscription price');
    });

    it('shows a subscription warning when removing group storage', function () {
        // Create group with storage enabled
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Remove Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/storage-remove-group/edit')
            ->click('text=Add Group Storage')
            ->click('#submit');

        // Now uncheck the storage checkbox — warning appears (saved = true, form = false)
        visit('/groups/storage-remove-group/edit')
            ->assertSee('Add Group Storage')
            ->click('text=Add Group Storage')
            ->assertSee('Changing this group storage may affect your subscription price');
    });

    it('can remove group storage and it is gone after saving', function () {
        // Create group with storage
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Storage Gone Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/storage-gone-group/edit')
            ->click('text=Add Group Storage')
            ->click('#submit');

        // Verify storage was saved
        visit('/groups/storage-gone-group/edit')
            ->assertSee('Group Storage Amount');

        // Now remove the storage and save
        visit('/groups/storage-gone-group/edit')
            ->click('text=Add Group Storage')
            ->click('#submit');

        // After save, storage is gone — no Group Storage Amount selector
        visit('/groups/storage-gone-group/edit')
            ->assertDontSee('Group Storage Amount');
    });

    it('shows max reached message when storage limit is exceeded', function () {
        // Activate with a very tight plan: max = 1 storage unit
        $demo_app = Application::where('slug', 'demo_app')->first();
        $app_instance = $demo_app->instances()->first();
        $plan = $app_instance->plan;
        $settings = $plan->settings ?? [];
        $settings['storage'] = ['max' => 1, 'price' => 0, 'amount' => 10];
        $plan->settings = $settings;
        $plan->save();

        // Create a group and use the one storage slot
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'First Storage Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/first-storage-group/edit')
            ->click('text=Add Group Storage')
            ->click('#submit');

        // Create a second group — it should show "max reached" message
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Second Storage Group')
            ->click('#category')
            ->click('text=Team')
            ->click('#submit');

        visit('/groups/second-storage-group/edit')
            ->assertSee("You've reached the maximum additional storage for your plan");
    });
});
