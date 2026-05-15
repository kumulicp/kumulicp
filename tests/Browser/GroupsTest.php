<?php

use App\User;

describe('Groups', function () {
    beforeEach(function () {
        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);
    });

    it('can create, update and delete a group', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Engineering Team')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('Edit Engineering Team Group')
            ->assertValue('#name input', 'Engineering Team')
            ->fill('#name input', 'Updated Name')
            ->click('#submit')
            ->assertSee('Updated Name');
        visit('/groups')
            ->click('.table-row button')
            ->assertSee('Remove Updated Name?')
            ->click('#delete')
            ->assertPathIs('/groups')
            ->assertSee('No Groups Available');
    });
});
