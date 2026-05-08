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

    it('shows a validation error when first name is cleared', function () {
        visit('/profile')
            ->fill('#firstName input', '')
            ->click('#submit')
            ->assertSee('first name');
    });

    it('shows a validation error when last name is cleared', function () {
        visit('/profile')
            ->fill('#lastName input', '')
            ->click('#submit')
            ->assertSee('last name');
    });

    it('shows a validation error when personal email is invalid', function () {
        visit('/profile')
            ->fill('#personalEmail input', 'not-an-email')
            ->click('#submit')
            ->assertSee('personal email');
    });

    it('shows the change password modal when the button is clicked', function () {
        visit('/profile')
            ->click('#changePassword')
            ->assertSee('Change Password')
            ->assertSee('Current Password')
            ->assertSee('New Password')
            ->assertSee('Confirm New Password');
    });

    it('shows a validation error when the current password is wrong', function () {
        visit('/profile')
            ->click('#changePassword')
            ->fill('#currentPassword input', 'wrongpassword')
            ->fill('#password input', 'NewPass123!')
            ->fill('#passwordConfirmation input', 'NewPass123!')
            ->click('#updatePassword')
            ->assertSee('current password');
    });

    it('shows a validation error when new passwords do not match', function () {
        visit('/profile')
            ->click('#changePassword')
            ->fill('#currentPassword input', 'demouser')
            ->fill('#password input', 'NewPass123!')
            ->fill('#passwordConfirmation input', 'DifferentPass456!')
            ->click('#updatePassword')
            ->assertSee('password');
    });

    it('redirects unauthenticated users away from the profile page', function () {
        visit('/logout')
            ->visit('/profile')
            ->assertPathIs('/login');
    });
});
