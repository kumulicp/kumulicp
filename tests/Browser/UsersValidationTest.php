<?php

describe('Users validation', function () {
    beforeEach(function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    it('shows validation errors when creating a user with invalid data', function () {
        visit('/users')
            ->click('#createUser')
            ->fill('#username input', 'test-user!')
            ->fill('#personalEmail input', 'not-an-email')
            ->click('#submit')
            ->assertPathIs('/users')
            ->assertSee('only contain letters and numbers')
            ->assertSee('first name field is required')
            ->assertSee('last name field is required')
            ->assertSee('must be a valid email address');
    });

    it('shows validation errors when updating a user with invalid data', function () {
        visit('/users/demo/edit')
            ->fill('#firstName input', '')
            ->fill('#lastName input', '')
            ->fill('#personalEmail input', 'not-an-email')
            ->click('#submit')
            ->assertPathIs('/users/demo/edit')
            ->assertSee('first name field is required')
            ->assertSee('last name field is required')
            ->assertSee('must be a valid email address');
    });
});
