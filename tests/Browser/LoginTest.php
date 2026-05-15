<?php

use App\User;

describe('Login', function () {
    it('renders the login page and allows toggling remember me', function () {
        $page = visit('/login');
        $page->assertSee('Email')
            ->assertSee('Password')
            ->assertSee('Keep me logged in')
            ->assertSee('Forgot');
        $page->assertNotChecked('#remember');
        $page->script("document.querySelector('#remember').closest('.va-checkbox__input-container').click()");
        $page->assertChecked('#remember');
    });

    it('logs in with valid credentials and redirects to the dashboard', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    it('navigates to the forgot password page via the link', function () {
        visit('/login')
            ->click('a[href="/password/reset"]')
            ->assertPathIs('/password/reset');
    });
});
