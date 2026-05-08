<?php

describe('Users validation', function () {
    beforeEach(function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    describe('creating a user', function () {
        it('rejects a missing username', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#firstName input', 'Test')
                ->fill('#lastName input', 'User')
                ->fill('#personalEmail input', 'test@example.com')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('username field is required');
        });

        it('rejects a username with uppercase letters', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'TestUser')
                ->fill('#firstName input', 'Test')
                ->fill('#lastName input', 'User')
                ->fill('#personalEmail input', 'test@example.com')
                ->click('#submit')
                ->assertPathIs('/users');
        });

        it('rejects a username with special characters', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'test-user!')
                ->fill('#firstName input', 'Test')
                ->fill('#lastName input', 'User')
                ->fill('#personalEmail input', 'test@example.com')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('only contain letters and numbers');
        });

        it('rejects a duplicate username', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'demo')
                ->fill('#firstName input', 'Test')
                ->fill('#lastName input', 'User')
                ->fill('#personalEmail input', 'test@example.com')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('User already exists');
        });

        it('rejects a missing first name', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'testuser')
                ->fill('#lastName input', 'User')
                ->fill('#personalEmail input', 'test@example.com')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('first name field is required');
        });

        it('rejects a missing last name', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'testuser')
                ->fill('#firstName input', 'Test')
                ->fill('#personalEmail input', 'test@example.com')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('last name field is required');
        });

        it('rejects a missing email', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'testuser')
                ->fill('#firstName input', 'Test')
                ->fill('#lastName input', 'User')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('personal email field is required');
        });

        it('rejects a malformed email address', function () {
            visit('/users')
                ->click('#createUser')
                ->fill('#username input', 'testuser')
                ->fill('#firstName input', 'Test')
                ->fill('#lastName input', 'User')
                ->fill('#personalEmail input', 'not-an-email')
                ->click('#submit')
                ->assertPathIs('/users')
                ->assertSee('must be a valid email address');
        });

        it('rejects an email already used by another user', function () {
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
    });

    describe('updating a user', function () {
        it('rejects a missing first name', function () {
            visit('/users/demo/edit')
                ->fill('#firstName input', '')
                ->click('#submit')
                ->assertPathIs('/users/demo/edit')
                ->assertSee('first name field is required');
        });

        it('rejects a missing last name', function () {
            visit('/users/demo/edit')
                ->fill('#lastName input', '')
                ->click('#submit')
                ->assertPathIs('/users/demo/edit')
                ->assertSee('last name field is required');
        });

        it('rejects a malformed email address', function () {
            visit('/users/demo/edit')
                ->fill('#personalEmail input', 'not-an-email')
                ->click('#submit')
                ->assertPathIs('/users/demo/edit')
                ->assertSee('must be a valid email address');
        });

        it('rejects an email already used by another user', function () {
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
});
