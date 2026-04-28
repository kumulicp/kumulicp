<?php

use App\User;

describe('Forgot Password', function () {
    it('renders the password recovery form', function () {
        visit('/password/reset')
            ->assertSee('Email')
            ->assertSee('Reset password');
    });

    it('shows a validation error when email field is empty', function () {
        visit('/password/reset')
            ->click('button[type=submit]')
            ->assertSee('required');
    });

    it('shows an error when the email address is not registered', function () {
        visit('/password/reset')
            ->fill('input[type=email]', 'nobody@example.com')
            ->click('button[type=submit]')
            ->assertSee("find");
    });

    it('shows a success message after submitting a registered email', function () {
        $user = User::factory()->create();

        visit('/password/reset')
            ->fill('input[type=email]', $user->email)
            ->click('button[type=submit]')
            ->assertSee('Check your email');
    });

    it('navigates back to login via the login tab', function () {
        visit('/password/reset')
            ->click('#login')
            ->assertPathIs('/login');
    });
});
