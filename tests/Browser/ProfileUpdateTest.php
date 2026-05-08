<?php

describe('Profile Update', function () {
    beforeEach(function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
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
            ->assertSee('Demo')
            ->assertSee('User')
            ->assertSee('demo@example.com');
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

});
