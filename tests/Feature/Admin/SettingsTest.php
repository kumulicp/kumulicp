<?php

namespace Tests\Feature\Admin;

use App\SsoProvider;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $support = new TestSupports;
        $support->seed();
        $this->admin = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->admin);
    }

    // ---------------------------------------------------------------------------
    // Auth guard
    // ---------------------------------------------------------------------------

    public function test_unauthenticated_user_is_redirected_from_admin_settings()
    {
        auth()->logout();

        $this->get('/admin/settings')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------------------
    // Control Panel Settings
    // ---------------------------------------------------------------------------

    public function test_control_panel_settings_update_succeeds_with_valid_data()
    {
        $response = $this->put('/admin/settings', [
            'base_domain' => 'newdomain.dev',
            'terms_url' => 'https://example.com/terms',
            'docs_url' => 'https://example.com/docs',
            'support_email' => 'support@example.com',
            'error_email' => 'error@example.com',
            'welcome_page' => '<p>Welcome</p>',
            'primary_color' => '#123456',
            'secondary_color' => '#abcdef',
            'default_currency' => 'USD',
            'enabled_currencies' => ['USD'],
        ]);

        $response->assertRedirect('admin/settings');
        $this->assertDatabaseHas('server_settings', ['key' => 'base_domain', 'value' => 'newdomain.dev']);
    }

    public function test_control_panel_settings_requires_base_domain()
    {
        $response = $this->put('/admin/settings', [
            'base_domain' => '',
        ]);

        $response->assertSessionHasErrors('base_domain');
    }

    public function test_control_panel_settings_rejects_invalid_support_email()
    {
        $response = $this->put('/admin/settings', [
            'base_domain' => 'example.dev',
            'support_email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('support_email');
    }

    public function test_control_panel_settings_rejects_invalid_error_email()
    {
        $response = $this->put('/admin/settings', [
            'base_domain' => 'example.dev',
            'error_email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('error_email');
    }

    // ---------------------------------------------------------------------------
    // Invoice Settings
    // ---------------------------------------------------------------------------

    public function test_invoice_settings_update_succeeds_with_valid_data()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload());

        $response->assertRedirect('admin/settings/invoice');
        $this->assertDatabaseHas('server_settings', ['key' => 'invoice_vendor_name', 'value' => 'Acme Corp']);
    }

    public function test_invoice_settings_requires_vendor_name()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_name' => '']));

        $response->assertSessionHasErrors('invoice_vendor_name');
    }

    public function test_invoice_settings_requires_vendor_product()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_product' => '']));

        $response->assertSessionHasErrors('invoice_vendor_product');
    }

    public function test_invoice_settings_requires_vendor_street()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_street' => '']));

        $response->assertSessionHasErrors('invoice_vendor_street');
    }

    public function test_invoice_settings_requires_vendor_location()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_location' => '']));

        $response->assertSessionHasErrors('invoice_vendor_location');
    }

    public function test_invoice_settings_requires_vendor_phone()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_phone_number' => '']));

        $response->assertSessionHasErrors('invoice_vendor_phone_number');
    }

    public function test_invoice_settings_requires_vendor_email()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_email' => '']));

        $response->assertSessionHasErrors('invoice_vendor_email');
    }

    public function test_invoice_settings_requires_vendor_url()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_url' => '']));

        $response->assertSessionHasErrors('invoice_vendor_url');
    }

    public function test_invoice_settings_vat_is_optional()
    {
        $response = $this->put('/admin/settings/invoice', $this->validInvoicePayload(['invoice_vendor_vat' => null]));

        $response->assertRedirect('admin/settings/invoice');
    }

    // ---------------------------------------------------------------------------
    // LDAP Settings
    // ---------------------------------------------------------------------------

    public function test_ldap_settings_update_succeeds_with_valid_data()
    {
        $response = $this->put('/admin/settings/ldap', [
            'name' => 'displayName',
            'first_name' => 'givenName',
            'last_name' => 'sn',
            'email' => 'mail',
            'phone_number' => 'telephoneNumber',
            'username' => 'uid',
            'personal_email' => 'mail',
            'org_email' => 'organizationMail',
            'access_type' => 'employeeType',
            'password' => 'userPassword',
        ]);

        $response->assertRedirect('admin/settings/ldap');
        $this->assertDatabaseHas('server_settings', ['key' => 'ldap_first_name', 'value' => 'givenName']);
    }

    public function test_ldap_settings_all_fields_are_optional()
    {
        $response = $this->put('/admin/settings/ldap', []);

        $response->assertRedirect('admin/settings/ldap');
    }

    public function test_ldap_settings_rejects_field_exceeding_max_length()
    {
        $response = $this->put('/admin/settings/ldap', [
            'first_name' => str_repeat('x', 101),
        ]);

        $response->assertSessionHasErrors('first_name');
    }

    // ---------------------------------------------------------------------------
    // SSO Providers
    // ---------------------------------------------------------------------------

    public function test_sso_provider_can_be_created_with_valid_data()
    {
        $response = $this->post('/admin/settings/sso-providers', [
            'name' => 'my-provider',
            'label' => 'My Provider',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sso_providers', ['name' => 'my-provider', 'label' => 'My Provider']);
    }

    public function test_sso_provider_store_requires_name()
    {
        $response = $this->post('/admin/settings/sso-providers', [
            'name' => '',
            'label' => 'My Provider',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_sso_provider_store_requires_label()
    {
        $response = $this->post('/admin/settings/sso-providers', [
            'name' => 'my-provider',
            'label' => '',
        ]);

        $response->assertSessionHasErrors('label');
    }

    public function test_sso_provider_store_requires_alpha_dash_name()
    {
        $response = $this->post('/admin/settings/sso-providers', [
            'name' => 'invalid name!',
            'label' => 'My Provider',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_sso_provider_store_rejects_duplicate_name()
    {
        SsoProvider::create(['name' => 'existing', 'label' => 'Existing', 'driver' => 'oidc', 'enabled' => false]);

        $response = $this->post('/admin/settings/sso-providers', [
            'name' => 'existing',
            'label' => 'Another Provider',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_sso_provider_update_succeeds_with_valid_data()
    {
        $provider = SsoProvider::create([
            'name' => 'test-provider',
            'label' => 'Test Provider',
            'driver' => 'oidc',
            'enabled' => false,
            'scopes' => 'openid email profile',
        ]);

        $response = $this->put('/admin/settings/sso-providers/'.$provider->id, [
            'name' => 'updated-provider',
            'label' => 'Updated Provider',
            'enabled' => false,
            'client_id' => null,
            'client_secret' => null,
            'base_url' => null,
            'redirect_url' => null,
            'scopes' => null,
        ]);

        $response->assertRedirect('/admin/settings/sso-providers/'.$provider->id);
        $this->assertDatabaseHas('sso_providers', ['id' => $provider->id, 'name' => 'updated-provider']);
    }

    public function test_sso_provider_update_requires_client_id_when_enabled()
    {
        $provider = SsoProvider::create([
            'name' => 'test-provider',
            'label' => 'Test Provider',
            'driver' => 'oidc',
            'enabled' => false,
            'scopes' => 'openid',
        ]);

        $response = $this->put('/admin/settings/sso-providers/'.$provider->id, [
            'name' => 'test-provider',
            'label' => 'Test Provider',
            'enabled' => true,
            'client_id' => null,
            'client_secret' => 'secret',
            'base_url' => 'https://sso.example.com',
            'redirect_url' => 'https://app.example.com/callback',
            'scopes' => 'openid email',
        ]);

        $response->assertSessionHasErrors('client_id');
    }

    public function test_sso_provider_update_requires_valid_urls_when_enabled()
    {
        $provider = SsoProvider::create([
            'name' => 'test-provider',
            'label' => 'Test Provider',
            'driver' => 'oidc',
            'enabled' => false,
            'scopes' => 'openid',
        ]);

        $response = $this->put('/admin/settings/sso-providers/'.$provider->id, [
            'name' => 'test-provider',
            'label' => 'Test Provider',
            'enabled' => true,
            'client_id' => 'client-abc',
            'client_secret' => 'secret',
            'base_url' => 'not-a-url',
            'redirect_url' => 'not-a-url',
            'scopes' => 'openid email',
        ]);

        $response->assertSessionHasErrors(['base_url', 'redirect_url']);
    }

    public function test_sso_provider_can_be_deleted()
    {
        $provider = SsoProvider::create([
            'name' => 'to-delete',
            'label' => 'To Delete',
            'driver' => 'oidc',
            'enabled' => false,
            'scopes' => '',
        ]);

        $this->delete('/admin/settings/sso-providers/'.$provider->id)
            ->assertOk();

        $this->assertDatabaseMissing('sso_providers', ['id' => $provider->id]);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function validInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'invoice_vendor_name' => 'Acme Corp',
            'invoice_vendor_product' => 'Cloud Services',
            'invoice_vendor_street' => '123 Main St',
            'invoice_vendor_location' => 'Springfield 12345',
            'invoice_vendor_phone_number' => '555-1234',
            'invoice_vendor_email' => 'billing@acme.com',
            'invoice_vendor_url' => 'https://acme.com',
            'invoice_vendor_vat' => 'US123456789',
        ], $overrides);
    }
}
