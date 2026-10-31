<?php

namespace Tests\Feature\Admin;

use App\AuditLog;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $this->admin = User::where('username', 'demo')->firstOrFail();
    }

    private function settingsPayload(array $extra = []): array
    {
        return array_merge([
            'base_domain' => 'local.dev',
            'terms_url' => 'https://example.com/terms',
            'docs_url' => 'https://example.com/docs',
            'welcome_page' => '<p>Welcome</p>',
            'primary_color' => '#123456',
            'secondary_color' => '#abcdef',
            'default_currency' => 'USD',
            'enabled_currencies' => ['USD'],
        ], $extra);
    }

    public function test_audit_logging_can_be_switched_on_and_off_from_the_admin_settings()
    {
        $this->actingAs($this->admin);

        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true]))
            ->assertRedirect('admin/settings');

        $this->assertDatabaseHas('server_settings', ['key' => 'audit_logging_enabled', 'value' => '1']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'audit.enabled', 'actor_id' => $this->admin->id]);

        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => false]));

        $this->assertDatabaseMissing('server_settings', ['key' => 'audit_logging_enabled']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'audit.disabled']);
    }

    public function test_saving_settings_without_the_audit_field_leaves_the_setting_alone()
    {
        $this->actingAs($this->admin);
        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true]));

        $this->put('/admin/settings', $this->settingsPayload());

        $this->assertDatabaseHas('server_settings', ['key' => 'audit_logging_enabled', 'value' => '1']);
    }

    public function test_the_audit_setting_must_be_a_boolean()
    {
        $this->actingAs($this->admin);

        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => 'maybe']))
            ->assertSessionHasErrors('audit_logging_enabled');
    }

    public function test_nothing_is_recorded_for_requests_while_auditing_is_off()
    {
        $this->actingAs($this->admin);

        $this->put('/admin/settings', $this->settingsPayload(['support_email' => 'help@example.com']));

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_write_requests_and_setting_changes_are_recorded_when_enabled()
    {
        $this->actingAs($this->admin);
        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true]));

        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true, 'support_email' => 'help@example.com']));

        $change = AuditLog::where('event', 'settings.changed')->firstOrFail();
        $request = AuditLog::where('event', 'http.request')->latest('id')->firstOrFail();

        $this->assertSame(['old' => null, 'new' => 'help@example.com'], $change->diff['support_email']);
        $this->assertSame('PUT', $request->properties['method']);
        $this->assertSame($this->admin->id, $request->actor_id);
        $this->assertNotEmpty($request->request_id);
    }

    public function test_failed_and_successful_logins_are_recorded_without_the_password()
    {
        $this->actingAs($this->admin);
        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true]));
        auth()->logout();

        $this->post('/login', ['email' => 'demo@example.com', 'password' => 'wrong-password']);
        $failed = AuditLog::where('event', 'auth.login_failed')->firstOrFail();

        $this->assertSame('failure', $failed->outcome);
        $this->assertSame('demo@example.com', $failed->properties['identifier']);
        $this->assertStringNotContainsString('wrong-password', json_encode($failed->toArray()));

        $this->post('/login', ['email' => 'demo@example.com', 'password' => 'demouser']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'actor_id' => $this->admin->id]);
    }

    public function test_admins_can_view_and_filter_the_audit_log_and_viewing_is_audited()
    {
        $this->actingAs($this->admin);
        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true]));

        $this->get('/admin/server/audit?event=audit.enabled')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AuditLogsList')
                ->where('enabled', true)
                ->has('entries', 1)
                ->where('entries.0.event', 'audit.enabled'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'audit.viewed']);
    }

    public function test_the_audit_log_can_be_exported_as_csv_and_the_export_is_audited()
    {
        $this->actingAs($this->admin);
        $this->put('/admin/settings', $this->settingsPayload(['audit_logging_enabled' => true]));

        $response = $this->get('/admin/server/audit/export');

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('occurred_at,event,outcome', $csv);
        $this->assertStringContainsString('audit.enabled', $csv);
        $this->assertDatabaseHas('audit_logs', ['event' => 'audit.exported']);
    }

    public function test_audit_log_filters_are_validated()
    {
        $this->actingAs($this->admin);

        $this->get('/admin/server/audit?outcome=nonsense')->assertSessionHasErrors('outcome');
    }
}
