<?php

use App\User;
use Tests\Support\TestSupports;

describe('Profile Update', function () {
    beforeEach(function () {
        $this->actingAs(User::where('email', 'demo@example.com')->first());
    });

    afterEach(function () {
        (new TestSupports())->cleanLdap();
    });

    it('shows the profile page with pre-filled form fields', function () {
        visit('/profile')
            ->assertSee('First Name')
            ->assertSee('Last Name')
            ->assertSee('Phone Number')
            ->assertSee('Personal Email')
            ->assertSee('Password')
            ->assertValue('#firstName input', 'Demo')
            ->assertValue('#lastName input', 'User')
            ->assertValue('#personalEmail input', 'demo@example.com')
            ->fill('#firstName input', 'Jane')
            ->fill('#lastName input', 'Smith')
            ->fill('#personalEmail input', 'jane.smith@example.com')
            ->fill('#phoneNumber input', '555 123-4567')
            ->click('#submit')
            ->assertPathIs('/profile')
            ->assertSee('Profile was updated!');
    });

    it('changes the password', function () {
        $page = visit('/profile');
        $page->click('#changePassword')
            ->assertSee('Change Password')
            ->assertSee('Current Password')
            ->assertSee('New Password')
            ->assertSee('Confirm New Password');
        $page->fill('#currentPassword input', 'demouser')
            ->fill('#password input', 'NewStr0ng@Pass1')
            ->fill('#passwordConfirmation input', 'NewStr0ng@Pass1')
            ->click('#updatePassword')
            ->assertPathIs('/profile')
            ->assertSee('Password updated!');
    });
});
