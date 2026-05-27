<?php

use App\User;
use Tests\Support\TestSupports;

describe('Groups', function () {
    beforeEach(function () {
        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);
    });

    afterEach(function () {
        (new TestSupports)->cleanLdap();
    });

    it('can create, update and delete a group', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Engineering Team')
            ->click('#category')
            ->click('text="Other"')
            ->click('#submit')
            ->assertSee('Edit Engineering Team Group')
            ->assertValue('#name input', 'Engineering Team')
            ->fill('#name input', 'Updated Name')
            ->click('#submit')
            ->assertSee('Updated Name');
        visit('/groups')
            ->click('tr:has-text("Updated Name") button')
            ->assertSee('Remove Updated Name?')
            ->click('#delete')
            ->assertPathIs('/groups')
            ->assertSee('No Groups Available');
    });
});
