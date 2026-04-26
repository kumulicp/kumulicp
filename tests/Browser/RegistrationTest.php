<?php

use App\Plan;
use App\Support\Facades\Settings;
use App\User;

// Seed a default plan before each test so that can.register evaluates to true.
// The registration form is gated behind Plan::where('is_default', 1)->count() > 0.
beforeEach(function () {
    Plan::create([
        'name' => 'Test Plan',
        'description' => 'Default plan for browser tests',
        'org_type' => 'nonprofit',
        'is_default' => true,
        'archive' => false,
    ]);
    Settings::update('installed', true);
});

afterEach(function () {
    Plan::where('name', 'Test Plan')->delete();
});

describe('Registration', function () {
    it('renders the registration form when a default plan exists', function () {
        visit('/register')
            ->assertSee('User Info')
            ->assertSee('Register');
    });

    it('shows all required form fields', function () {
        visit('/register')
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

    it('shows a validation error when the username is too short', function () {
        visit('/register')
            ->fill('#username', 'usr')
            ->fill('#contactEmail', 'test@example.com')
            ->fill('#password', 'Password123!')
            ->fill('#passwordConfirmation', 'Password123!')
            ->click('#submit')
            ->assertSee('username');
    });

    it('shows a validation error when passwords do not match', function () {
        visit('/register')
            ->fill('#username', 'validuser')
            ->fill('#contactEmail', 'valid@example.com')
            ->fill('#password', 'Password123!')
            ->fill('#passwordConfirmation', 'DifferentPass456!')
            ->click('#submit')
            ->assertSee('password');
    });

    it('shows a validation error for a duplicate username', function () {
        User::factory()->create(['username' => 'takenuser']);

        visit('/register')
            ->fill('#username', 'takenuser')
            ->fill('#contactEmail', 'unique@example.com')
            ->fill('#password', 'Password123!')
            ->fill('#passwordConfirmation', 'Password123!')
            ->click('#submit')
            ->assertSee('username');
    });

    it('shows a validation error for an already registered email', function () {
        $user = User::factory()->create();

        visit('/register')
            ->fill('#username', 'brandnewuser')
            ->fill('#contactEmail', $user->email)
            ->fill('#password', 'Password123!')
            ->fill('#passwordConfirmation', 'Password123!')
            ->click('#submit')
            ->assertSee('email');
    });

    it('shows a validation error when terms are not accepted', function () {
        visit('/register')
            ->fill('#username', 'newusertest')
            ->fill('#contactEmail', 'newusertest@example.com')
            ->fill('#password', 'Password123!')
            ->fill('#passwordConfirmation', 'Password123!')
            ->fill('#contactFirstName', 'New')
            ->fill('#contactLastName', 'User')
            ->fill('#contactPhoneNumber', '555-123-4567')
            ->fill('#subdomain', 'newusertest')
            ->click('#submit')
            ->assertSee('terms');
    });
});
