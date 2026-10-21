<?php

namespace Tests\Feature\Admin;

use App\Jobs\Accounts\UpdateOrganization;
use App\Organization;
use App\OrgServer;
use App\Plan;
use App\SecurityScan;
use App\Server;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Settings;
use App\Support\Security\NamespaceSecurityPolicy;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;
use Tests\TestCase;

class NamespaceSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        (new TestSupports)->seed();

        return User::find(1);
    }

    private function planPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Plan',
            'description' => 'Description',
            'type' => 'package',
            'org_type' => 'none',
            'domain_enabled' => false,
            'email_enabled' => false,
        ], $overrides);
    }

    private function customTier(string $key = 'mine'): array
    {
        return [
            'key' => $key,
            'label' => 'Mine',
            'enforce' => 'baseline',
            'warn' => 'restricted',
            'audit' => null,
            'enforce_version' => 'latest',
            'warn_version' => 'v1.30',
            'audit_version' => 'latest',
        ];
    }

    // ---------------------------------------------------------------------------
    // Settings page
    // ---------------------------------------------------------------------------

    public function test_unauthenticated_user_is_redirected_from_the_settings_page()
    {
        (new TestSupports)->seed();

        $this->get('/admin/settings/namespace-security')->assertRedirect('/login');
    }

    public function test_non_admin_cannot_view_or_update_the_settings()
    {
        (new TestSupports)->seed();
        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)->get('/admin/settings/namespace-security')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/namespace-security', ['tiers' => []])->assertForbidden();
    }

    public function test_admin_sees_the_presets_and_no_custom_tiers_by_default()
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/settings/namespace-security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings/NamespaceSecuritySettings')
                ->has('presets', 4)
                ->has('tiers', 0)
            );
    }

    public function test_admin_can_add_a_custom_tier()
    {
        $this->actingAs($this->adminUser())
            ->put('/admin/settings/namespace-security', ['tiers' => [$this->customTier()]])
            ->assertRedirect('/admin/settings/namespace-security');

        $tier = NamespaceSecurityPolicy::tier('mine');
        $this->assertSame('baseline', $tier->enforce);
        $this->assertSame('restricted', $tier->warn);
        $this->assertSame('v1.30', $tier->warnVersion);

        $this->get('/admin/settings/namespace-security')
            ->assertInertia(fn ($page) => $page->has('tiers', 1));
    }

    public function test_a_custom_tier_cannot_reuse_a_preset_key_or_an_invalid_key_or_level()
    {
        $this->actingAs($this->adminUser())
            ->put('/admin/settings/namespace-security', ['tiers' => [
                array_merge($this->customTier(), ['key' => 'baseline']),
                array_merge($this->customTier(), ['key' => 'Not Valid']),
                array_merge($this->customTier(), ['key' => 'ok', 'enforce' => 'everything']),
            ]])
            ->assertSessionHasErrors(['tiers.0.key', 'tiers.1.key', 'tiers.2.enforce']);

        $this->assertSame([], NamespaceSecurityPolicy::customTiers());
    }

    public function test_removing_every_custom_tier_clears_the_setting()
    {
        $user = $this->adminUser();
        Settings::update(NamespaceSecurityPolicy::TIERS_SETTING, json_encode(['mine' => $this->customTier()]));

        $this->actingAs($user)
            ->put('/admin/settings/namespace-security', ['tiers' => []])
            ->assertRedirect('/admin/settings/namespace-security');

        $this->assertDatabaseMissing('server_settings', ['key' => NamespaceSecurityPolicy::TIERS_SETTING]);
    }

    public function test_a_tier_still_used_by_a_plan_cannot_be_removed()
    {
        $user = $this->adminUser();
        Settings::update(NamespaceSecurityPolicy::TIERS_SETTING, json_encode(['mine' => $this->customTier()]));
        Plan::factory()->create(['name' => 'Gold', 'settings' => ['security' => ['tier' => 'mine']]]);

        $this->actingAs($user)
            ->put('/admin/settings/namespace-security', ['tiers' => []])
            ->assertSessionHasErrors('tiers');

        $this->assertArrayHasKey('mine', NamespaceSecurityPolicy::customTiers());
    }

    // ---------------------------------------------------------------------------
    // Plan tier
    // ---------------------------------------------------------------------------

    public function test_admin_can_set_a_plan_s_security_tier()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'observe']]))
            ->assertRedirect('/admin/service/plans');

        $this->assertSame('observe', $plan->fresh()->setting('security.tier'));
    }

    public function test_choosing_the_none_tier_stores_nothing()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'observe']]]);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'none']]))
            ->assertRedirect('/admin/service/plans');

        $this->assertNull($plan->fresh()->setting('security.tier'));
    }

    public function test_an_unknown_tier_is_rejected()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'bogus']]))
            ->assertSessionHasErrors('security.tier');
    }

    public function test_changing_the_tier_re_syncs_the_plan_s_organizations()
    {
        Queue::fake();
        $user = $this->adminUser();
        $plan = Plan::factory()->create();
        $organization = Organization::factory()->create(['plan_id' => $plan->id]);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline']]))
            ->assertRedirect('/admin/service/plans');

        Queue::assertPushed(UpdateOrganization::class, fn ($job) => $job->organization->is($organization));
    }

    public function test_saving_a_plan_without_changing_its_tier_does_not_re_sync()
    {
        Queue::fake();
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'baseline']]]);
        Organization::factory()->create(['plan_id' => $plan->id]);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline']]))
            ->assertRedirect('/admin/service/plans');

        Queue::assertNotPushed(UpdateOrganization::class);
    }

    public function test_the_plan_edit_page_offers_the_tiers_and_the_current_choice()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'observe']]]);

        $this->actingAs($user)
            ->get("/admin/service/plans/{$plan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Plans/PlanEdit')
                ->has('security_tiers', 4)
                ->where('plan.security_tier', 'observe')
            );
    }

    // ---------------------------------------------------------------------------
    // Preflight gate
    // ---------------------------------------------------------------------------

    private function managedOrganization(Plan $plan): Organization
    {
        $server = Server::factory()->create([
            'interface' => 'helm_k8s',
            'address' => 'https://cluster.example.com:6443',
            'ca_cert' => 'fake-ca',
            'api_secret' => 'token',
            'settings' => ['k8s_auth_type' => 'bearer_token', 'security_mode' => 'managed'],
        ]);
        $organization = Organization::factory()->create(['plan_id' => $plan->id]);
        OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);

        return $organization;
    }

    private function fakeViolatingCluster(): void
    {
        Http::fake(['*' => Http::response([], 200, ['Warning' => [
            '299 - "existing pods in namespace \"x\" violate the new PodSecurity enforce level \"baseline:latest\""',
            '299 - "web-1: privileged (container \"c\" must not set securityContext.privileged=true)"',
        ]])]);
    }

    public function test_raising_a_plan_s_enforce_level_is_blocked_when_it_would_break_running_workloads()
    {
        Queue::fake();
        $user = $this->adminUser();
        $plan = Plan::factory()->create();
        $organization = $this->managedOrganization($plan);
        $this->fakeViolatingCluster();

        $this->actingAs($user)
            ->from("/admin/service/plans/{$plan->id}")
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline']]))
            ->assertSessionHasErrors('security.tier');

        $this->assertNull($plan->fresh()->setting('security.tier'));
        $this->assertStringContainsString($organization->slug, session('errors')->first('security.tier'));
        $this->assertSame(1, SecurityScan::where('tool', 'pod-security')->count());
        Queue::assertNotPushed(UpdateOrganization::class);
    }

    public function test_the_admin_can_override_the_preflight()
    {
        Queue::fake();
        $user = $this->adminUser();
        $plan = Plan::factory()->create();
        $this->managedOrganization($plan);
        $this->fakeViolatingCluster();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline', 'override_preflight' => true]]))
            ->assertRedirect('/admin/service/plans');

        $this->assertSame('baseline', $plan->fresh()->setting('security.tier'));
        Http::assertNothingSent();
    }

    public function test_a_clean_preflight_lets_the_tier_change_through()
    {
        Queue::fake();
        $user = $this->adminUser();
        $plan = Plan::factory()->create();
        $this->managedOrganization($plan);
        Http::fake(['*' => Http::response([])]);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline']]))
            ->assertRedirect('/admin/service/plans');

        $this->assertSame('baseline', $plan->fresh()->setting('security.tier'));
    }

    public function test_lowering_or_keeping_the_enforce_level_is_not_preflighted()
    {
        Queue::fake();
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'restricted']]]);
        $this->managedOrganization($plan);
        Http::fake();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline']]))
            ->assertRedirect('/admin/service/plans');
        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->planPayload(['security' => ['tier' => 'baseline']]))
            ->assertRedirect('/admin/service/plans');

        Http::assertNothingSent();
    }

    public function test_raising_a_custom_tier_s_enforce_level_is_preflighted_for_the_plans_using_it()
    {
        $user = $this->adminUser();
        $tier = array_merge($this->customTier(), ['enforce' => null]);
        Settings::update(NamespaceSecurityPolicy::TIERS_SETTING, json_encode(['mine' => $tier]));
        $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'mine']]]);
        $this->managedOrganization($plan);
        $this->fakeViolatingCluster();

        $this->actingAs($user)
            ->put('/admin/settings/namespace-security', ['tiers' => [array_merge($this->customTier(), ['enforce' => 'baseline'])]])
            ->assertSessionHasErrors('tiers');

        $this->assertNull(NamespaceSecurityPolicy::tier('mine')->enforce);

        $this->actingAs($user)
            ->put('/admin/settings/namespace-security', ['override_preflight' => true, 'tiers' => [array_merge($this->customTier(), ['enforce' => 'baseline'])]])
            ->assertRedirect('/admin/settings/namespace-security');

        $this->assertSame('baseline', NamespaceSecurityPolicy::tier('mine')->enforce);
    }

    // ---------------------------------------------------------------------------
    // Server mode
    // ---------------------------------------------------------------------------

    public function test_a_server_s_security_mode_must_be_a_known_mode()
    {
        $user = $this->adminUser();
        $server = Server::factory()->create(['interface' => 'helm_k8s', 'settings' => []]);

        $payload = [
            'name' => 'Cluster',
            'host' => 'host',
            'address' => 'https://cluster.example.com:6443',
            'ip' => '127.0.0.1',
            'internal_address' => 'localhost',
        ];

        $this->actingAs($user)
            ->put("/admin/server/servers/{$server->id}", $payload + ['settings' => ['security_mode' => 'sometimes']])
            ->assertSessionHasErrors('settings.security_mode');

        $this->actingAs($user)
            ->put("/admin/server/servers/{$server->id}", $payload + ['settings' => ['security_mode' => 'managed']])
            ->assertSessionHasNoErrors();

        $this->assertSame('managed', NamespaceSecurityPolicy::mode($server->fresh()));
    }
}
