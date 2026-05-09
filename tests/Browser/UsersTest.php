<?php

use App\Support\Facades\AccountManager;
use App\User;

describe('Users', function () {
    beforeEach(function () {
        $this->actingAs(User::find(1));
    });

    afterEach(function () {
        foreach (['testuser', 'editme', 'deleteme'] as $username) {
            $user = AccountManager::users()->find($username);
            if ($user) {
                $user->delete();
            }
        }
    });

    it('adds a new user', function () {
        visit('/users')
            ->assertSee('Users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'testuser@example.com')
            ->click('#submit')
            ->assertPathIs('/users/testuser/permissions');
    });

    it('updates an existing user', function () {
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'editme')
            ->fill('#firstName input', 'Edit')
            ->fill('#lastName input', 'Me')
            ->fill('#personalEmail input', 'editme@example.com')
            ->click('#submit');

        visit('/users/editme/edit')
            ->assertValue('#firstName input', 'Edit')
            ->assertValue('#lastName input', 'Me')
            ->assertValue('#personalEmail input', 'editme@example.com')
            ->fill('#firstName input', 'Updated')
            ->fill('#lastName input', 'Name')
            ->click('#submit')
            ->assertPathIs('/users/editme')
            ->assertSee('Updated Name');
    });

    it('removes a user', function () {
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'deleteme')
            ->fill('#firstName input', 'Delete')
            ->fill('#lastName input', 'Me')
            ->fill('#personalEmail input', 'deleteme@example.com')
            ->click('#submit');

        visit('/users')
            ->click('#deletedeleteme')
            ->click('#delete')
            ->assertPathIs('/users')
            ->assertDontSee('Delete Me');
    });
});
