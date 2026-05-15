<?php

use App\User;

describe('Forgot Password', function () {
    it('renders the password recovery form and navigates back to login', function () {
        visit('/password/reset')
            ->assertSee('Email')
            ->assertSee('Reset password')
            ->click('#login')
            ->assertPathIs('/login');
    });

    it('shows an error when the email address is not registered', function () {
        visit('/password/reset')
            ->fill('input[type=email]', 'nobody@example.com')
            ->click('button[type=submit]')
            ->assertSee('find');
    });

    it('shows a success message after submitting a registered email', function () {
        $user = User::factory()->create();

        visit('/password/reset')
            ->fill('input[type=email]', $user->email)
            ->click('button[type=submit]')
            ->assertSee('Check your email');
    });
});
