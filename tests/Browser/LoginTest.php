<?php

// The login form itself is covered by Feature/Auth. This is the one browser test that checks the compiled
// frontend bundle loads and the app boots, so keep it as a smoke test for dependency bumps
describe('Login', function () {
    it('logs in with valid credentials and redirects to the dashboard', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });
});
