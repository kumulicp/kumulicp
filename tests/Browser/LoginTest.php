<?php

use App\User;

describe('Login', function () {
    it('renders the login page with all required fields', function () {
        visit('/login')
            ->assertSee('Email')
            ->assertSee('Password')
            ->assertSee('Keep Logged In')
            ->assertSee('Forgot');
    });

    it('shows a credential error for invalid email and password', function () {
        visit('/login')
            ->fill('input[type=email]', 'nobody@example.com')
            ->fill('input[type=password]', 'wrong-password')
            ->click('#submit')
            ->assertSee('These credentials do not match our records');
    });

    it('shows a credential error when the password is wrong for a real user', function () {
        $user = User::factory()->create();

        visit('/login')
            ->fill('input[type=email]', $user->email)
            ->fill('input[type=password]', 'wrong-password')
            ->click('#submit')
            ->assertSee('These credentials do not match our records');
    });

    it('logs in and redirects to the dashboard with valid credentials', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    it('navigates to the forgot password page via the link', function () {
        visit('/login')
            ->click('a[href="/password/reset"]')
            ->assertPathIs('/password/reset');
    });

    it('redirects to login when accessing the dashboard while unauthenticated', function () {
        visit('/')
            ->assertPathIs('/login');
    });
});
