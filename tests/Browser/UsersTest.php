<?php

use App\Support\Facades\AccountManager;
use App\User;

describe('Users', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    afterEach(function () {
        $user = AccountManager::users()->find('testuser');
        if ($user) {
            $user->delete();
        }
    });

    it('can create, update and delete a user', function () {
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'testuser@example.com')
            ->fill('#phoneNumber input', '123 456-7890')
            ->click('#submit')
            ->assertPathIs('/users/testuser/permissions');

        visit('/users/testuser/edit')
            ->assertValue('#firstName input', 'Test')
            ->assertValue('#lastName input', 'User')
            ->assertValue('#personalEmail input', 'testuser@example.com')
            ->assertValue('#phoneNumber input', '123 456-7890')
            ->fill('#firstName input', 'Updated')
            ->fill('#lastName input', 'Name')
            ->fill('#phoneNumber input', '098 765-4321')
            ->click('#submit')
            ->assertPathIs('/users/testuser')
            ->assertSee('Updated Name');

        visit('/users')
            ->click('#deletetestuser')
            ->click('#delete')
            ->assertPathIs('/users')
            ->assertDontSee('Updated Name');
    });
});
