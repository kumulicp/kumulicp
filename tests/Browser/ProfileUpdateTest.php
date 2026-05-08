<?php

use App\User;

describe('Profile Update', function () {
    beforeEach(function () {
        $user = User::where('email', 'demo@example.com')->first();
        $this->actingAs($user);
    });

    it('renders the profile edit page with all fields', function () {
        visit('/profile')
            ->assertSee('First Name')
            ->assertSee('Last Name')
            ->assertSee('Phone Number')
            ->assertSee('Personal Email')
            ->assertSee('Password');
    });

    it('displays the current user data pre-filled in the form', function () {
        visit('/profile')
            ->assertValue('#firstName input', 'Demo')
            ->assertValue('#lastName input', 'User')
            ->assertValue('#personalEmail input', 'demo@example.com');
    });

    it('successfully updates first name and last name', function () {
        visit('/profile')
            ->fill('#firstName input', 'Jane')
            ->fill('#lastName input', 'Smith')
            ->click('#submit')
            ->assertPathIs('/profile')
            ->assertSee('Profile was updated!');
    });

    it('successfully updates the personal email', function () {
        visit('/profile')
            ->fill('#personalEmail input', 'jane.smith@example.com')
            ->click('#submit')
            ->assertPathIs('/profile')
            ->assertSee('Profile was updated!');
    });

    it('successfully updates the phone number', function () {
        visit('/profile')
            ->fill('#phoneNumber input', '555 123-4567')
            ->click('#submit')
            ->assertPathIs('/profile')
            ->assertSee('Profile was updated!');
    });

    it('shows the change password modal when the button is clicked', function () {
        visit('/profile')
            ->click('#changePassword')
            ->assertSee('Change Password')
            ->assertSee('Current Password')
            ->assertSee('New Password')
            ->assertSee('Confirm New Password');
    });

    it('successfully changes the password', function () {
        visit('/profile')
            ->click('#changePassword')
            ->fill('#currentPassword input', 'demouser')
            ->fill('#password input', 'NewStr0ng@Pass1')
            ->fill('#passwordConfirmation input', 'NewStr0ng@Pass1')
            ->click('#updatePassword')
            ->assertPathIs('/profile')
            ->assertSee('Password updated!');
    });

});
