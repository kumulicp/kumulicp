<?php

use App\Plan;
use App\User;

describe('Registration', function () {
    it('renders the registration form with all required fields', function () {
        visit('/register')
            ->assertSee('User Info')
            ->assertSee('Register')
            ->assertSee('Username')
            ->assertSee('Password')
            ->assertSee('Subdomain name')
            ->assertSee('I agree to');
    });

    it('shows a message when registration is unavailable', function () {
        Plan::where('is_default', true)->update(['is_default' => false]);

        visit('/register')
            ->assertSee('Registration');
    });
});
