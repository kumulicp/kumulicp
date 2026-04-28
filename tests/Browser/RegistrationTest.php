<?php

use App\Plan;
use App\User;

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
            ->fill('#username input', 'usr')
            ->fill('#contactEmail input', 'test@example.com')
            ->fill('#password input', 'Password123!')
            ->fill('#passwordConfirmation input', 'Password123!')
            ->click('#submit')
            ->assertSee('username');
    });

    it('shows a validation error when passwords do not match', function () {
        visit('/register')
            ->fill('#username input', 'validuser')
            ->fill('#contactEmail input', 'valid@example.com')
            ->fill('#password input', 'Password123!')
            ->fill('#passwordConfirmation input', 'DifferentPass456!')
            ->click('#submit')
            ->assertSee('password');
    });

    it('shows a validation error for a duplicate username', function () {
        User::factory()->create(['username' => 'takenuser']);

        visit('/register')
            ->fill('#username input', 'takenuser')
            ->fill('#contactEmail input', 'unique@example.com')
            ->fill('#password input', 'Password123!')
            ->fill('#passwordConfirmation input', 'Password123!')
            ->click('#submit')
            ->assertSee('username');
    });

    it('shows a validation error for an already registered email', function () {
        $user = User::factory()->create();

        visit('/register')
            ->fill('#username input', 'brandnewuser')
            ->fill('#contactEmail input', $user->email)
            ->fill('#password input', 'Password123!')
            ->fill('#passwordConfirmation input', 'Password123!')
            ->click('#submit')
            ->assertSee('email');
    });

    it('shows a validation error when terms are not accepted', function () {
        visit('/register')
            ->fill('#username input', 'newusertest')
            ->fill('#contactEmail input', 'newusertest@example.com')
            ->fill('#password input', 'Password123!')
            ->fill('#passwordConfirmation input', 'Password123!')
            ->fill('#contactFirstName input', 'New')
            ->fill('#contactLastName input', 'User')
            ->fill('#contactPhoneNumber input', '555-123-4567')
            ->fill('#subdomain input', 'newusertest')
            ->click('#submit')
            ->assertSee('terms');
    });
});
