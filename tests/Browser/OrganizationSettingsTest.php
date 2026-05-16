<?php

use App\User;

describe('Organization Settings', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows the organization settings page with pre-filled data', function () {
        visit('/settings/organization')
            ->assertSee('Organization Name')
            ->assertSee('Organization Email')
            ->assertSee('Organization Phone Number')
            ->assertSee('Street')
            ->assertSee('ZIP Code')
            ->assertSee('City')
            ->assertSee('First Name')
            ->assertSee('Last Name')
            ->assertValue('#name input', 'Demo')
            ->assertValue('#orgEmail input', 'demoaccount@example.com')
            ->assertValue('#street input', '123 Demo St')
            ->assertValue('#zipcode input', '123 456')
            ->assertValue('#city input', 'Demotown')
            ->fill('#name input', 'Updated Organization Name')
            ->fill('#street input', '456 New Street')
            ->fill('#zipcode input', '90210')
            ->fill('#city input', 'New City')
            ->fill('#user_first_name input', 'Jane')
            ->fill('#user_last_name input', 'Smith')
            ->fill('#user_email input', 'jane.smith@example.com')
            ->click('#submit')
            ->assertPathIs('/settings/organization')
            ->assertValue('#name input', 'Updated Organization Name')
            ->assertValue('#street input', '456 New Street')
            ->assertValue('#zipcode input', '90210')
            ->assertValue('#city input', 'New City')
            ->assertValue('#user_first_name input', 'Jane')
            ->assertValue('#user_last_name input', 'Smith')
            ->assertValue('#user_email input', 'jane.smith@example.com');
    });
});
