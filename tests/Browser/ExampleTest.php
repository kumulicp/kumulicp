<?php

use App\User;

describe('Login page', function () {
    it('renders the login form', function () {
        visit('/login')
            ->assertSee('Login');
    });

    it('shows a validation error for invalid credentials', function () {
        visit('/login')
            ->fill('email', 'notauser@example.com')
            ->fill('password', 'wrong-password')
            ->click('button[type="submit"]')
            ->assertSee('These credentials do not match our records');
    });

    it('redirects to the dashboard after a successful login', function () {
        $user = User::factory()->create();

        visit('/login')
            ->fill('email', $user->email)
            ->fill('password', 'password')
            ->click('button[type="submit"]')
            ->assertUrlIs('/');
    });
});
