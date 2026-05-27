<?php

use App\SsoProvider;
use App\User;

describe('Admin Settings', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows control panel settings with pre-filled domain and updates settings', function () {
        visit('/admin/settings')
            ->assertSee('Control Panel')
            ->assertValue('#baseDomain input', 'local.dev')
            ->fill('#baseDomain input', 'updated.dev')
            ->fill('#supportEmail input', 'support@updated.dev')
            ->fill('#errorEmail input', 'error@updated.dev')
            ->click('#submit')
            ->assertPathIs('/admin/settings')
            ->assertSee('Settings updated');
    });

    it('shows invoice settings with pre-filled values and updates settings', function () {
        visit('/admin/settings/invoice')
            ->assertSee('Invoice')
            ->assertValue('#invoiceVendorName input', 'Demo Company')
            ->fill('#invoiceVendorName input', 'Updated Company')
            ->fill('#invoiceVendorProduct input', 'Updated Services')
            ->fill('#invoiceVendorStreet input', 'Updated St.')
            ->fill('#invoiceVendorLocation input', 'Updated City 12345')
            ->fill('#invoiceVendorEmail input', 'billing@updated.com')
            ->fill('#invoiceVendorUrl input', 'https://updated.com')
            ->click('#submit')
            ->assertPathIs('/admin/settings/invoice')
            ->assertSee('Settings updated');
    });

    it('shows LDAP settings and updates attribute mappings', function () {
        visit('/admin/settings/ldap')
            ->assertSee('LDAP')
            ->fill('#fullName input', 'displayName')
            ->fill('#firstName input', 'givenName')
            ->fill('#lastName input', 'sn')
            ->fill('#username input', 'uid')
            ->fill('#personalEmail input', 'mail')
            ->fill('#orgEmail input', 'organizationMail')
            ->click('#submit')
            ->assertPathIs('/admin/settings/ldap')
            ->assertSee('Settings updated');
    });

    it('creates an SSO provider', function () {
        visit('/admin/settings/sso-providers')
            ->assertSee('SSO Providers')
            ->click('#createProvider')
            ->fill('#name input', 'my-provider')
            ->fill('#label input', 'My Provider')
            ->click('#submit')
            ->assertSee('Provider added')
            ->assertValue('#label input', 'My Provider');

        expect(SsoProvider::where('name', 'my-provider')->exists())->toBeTrue();
    });

    it('edits an SSO provider', function () {
        $provider = SsoProvider::create([
            'name' => 'test-provider',
            'label' => 'Test Provider',
            'driver' => 'oidc',
            'enabled' => false,
            'scopes' => 'openid email profile',
        ]);

        visit('/admin/settings/sso-providers/'.$provider->id)
            ->assertValue('#name input', 'test-provider')
            ->assertValue('#label input', 'Test Provider')
            ->fill('#name input', 'updated-provider')
            ->fill('#label input', 'Updated Provider')
            ->fill('#client_id input', 'client-abc')
            ->fill('#client_secret input', 'secret-xyz')
            ->fill('#base_url input', 'https://sso.example.com')
            ->fill('#redirect_url input', 'https://app.example.com/callback')
            ->fill('#scopes input', 'openid email profile')
            ->click('#submit')
            ->assertPathIs('/admin/settings/sso-providers/'.$provider->id)
            ->assertSee('Provider updated');
    });
});
