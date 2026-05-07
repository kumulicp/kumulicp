<?php

describe('Organization Settings', function () {
    beforeEach(function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    it('renders the organization settings page with all fields', function () {
        visit('/settings/organization')
            ->assertSee('Organization Name')
            ->assertSee('Organization Email')
            ->assertSee('Organization Phone Number')
            ->assertSee('Street')
            ->assertSee('ZIP Code')
            ->assertSee('City')
            ->assertSee('First Name')
            ->assertSee('Last Name');
    });

    it('loads the page pre-populated with existing organization data', function () {
        visit('/settings/organization')
            ->assertInputValue('#name', 'Demo')
            ->assertInputValue('#orgEmail', 'demoaccount@example.com')
            ->assertInputValue('#street', '123 Demo St')
            ->assertInputValue('#zipcode', '123 456')
            ->assertInputValue('#city', 'Demotown');
    });

    it('updates organization name and description successfully', function () {
        visit('/settings/organization')
            ->fill('#name', 'Updated Organization Name')
            ->click('#submit')
            ->assertPathIs('/settings/organization')
            ->assertInputValue('#name', 'Updated Organization Name');
    });

    it('updates billing address fields successfully', function () {
        visit('/settings/organization')
            ->fill('#street', '456 New Street')
            ->fill('#zipcode', '90210')
            ->fill('#city', 'New City')
            ->click('#submit')
            ->assertPathIs('/settings/organization')
            ->assertInputValue('#street', '456 New Street')
            ->assertInputValue('#zipcode', '90210')
            ->assertInputValue('#city', 'New City');
    });

    it('updates billing contact info successfully', function () {
        visit('/settings/organization')
            ->fill('#user_first_name', 'Jane')
            ->fill('#user_last_name', 'Smith')
            ->fill('#user_email', 'jane.smith@example.com')
            ->click('#submit')
            ->assertPathIs('/settings/organization')
            ->assertInputValue('#user_first_name', 'Jane')
            ->assertInputValue('#user_last_name', 'Smith')
            ->assertInputValue('#user_email', 'jane.smith@example.com');
    });

    it('shows a validation error when organization name is missing', function () {
        visit('/settings/organization')
            ->fill('#name', '')
            ->click('#submit')
            ->assertSee('required');
    });

    it('shows a validation error when organization email is invalid', function () {
        visit('/settings/organization')
            ->fill('#orgEmail', 'not-a-valid-email')
            ->click('#submit')
            ->assertSee('valid email');
    });

    it('redirects to login when accessing settings while unauthenticated', function () {
        visit('/logout')
            ->visit('/settings/organization')
            ->assertPathIs('/login');
    });
});
