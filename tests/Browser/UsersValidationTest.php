<?php

describe('Users validation', function () {
    beforeEach(function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    it('rejects invalid data when creating a user', function () {
        // Missing username
        visit('/users')
            ->click('#createUser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'test@example.com')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('username field is required');

        // Uppercase username
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'TestUser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'test@example.com')
            ->click('#submit')
            ->assertPathIs('/users');

        // Special characters in username
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'test-user!')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'test@example.com')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('only contain letters and numbers');

        // Duplicate username
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'demo')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'test@example.com')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('User already exists');

        // Missing first name
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'test@example.com')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('first name field is required');

        // Missing last name
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#firstName input', 'Test')
            ->fill('#personalEmail input', 'test@example.com')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('last name field is required');

        // Missing email
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('personal email field is required');

        // Malformed email
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'not-an-email')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('must be a valid email address');

        // Email already taken
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'testuser')
            ->fill('#firstName input', 'Test')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'demo@example.com')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('already being used by another user');
    });

    it('rejects invalid data when updating a user', function () {
        // Missing first name
        visit('/users/demo/edit')
            ->fill('#firstName input', '')
            ->click('#submit')
            ->assertPathIs('/users/demo/edit')
            ->assertSee('first name field is required');

        // Missing last name
        visit('/users/demo/edit')
            ->fill('#lastName input', '')
            ->click('#submit')
            ->assertPathIs('/users/demo/edit')
            ->assertSee('last name field is required');

        // Malformed email
        visit('/users/demo/edit')
            ->fill('#personalEmail input', 'not-an-email')
            ->click('#submit')
            ->assertPathIs('/users/demo/edit')
            ->assertSee('must be a valid email address');

        // Email already used by another user
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'otherusr')
            ->fill('#firstName input', 'Other')
            ->fill('#lastName input', 'User')
            ->fill('#personalEmail input', 'other@example.com')
            ->click('#submit');

        visit('/users/demo/edit')
            ->fill('#personalEmail input', 'other@example.com')
            ->click('#submit')
            ->assertPathIs('/users/demo/edit')
            ->assertSee('already being used by another user');
    });
});
