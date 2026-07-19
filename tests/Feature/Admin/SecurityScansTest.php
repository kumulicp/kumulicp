<?php

namespace Tests\Feature\Admin;

use App\AppInstance;
use App\OrgDomain;
use App\SecurityFinding;
use App\SecurityScan;
use App\SecurityScanSavedValue;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class SecurityScansTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();

        return User::find(1);
    }

    public function test_unauthenticated_user_is_redirected_from_scans_index()
    {
        (new TestSupports)->seed();

        $this->get('/admin/server/security/scans')
            ->assertRedirect('/login');
    }

    public function test_non_admin_user_cannot_access_scans_index()
    {
        $support = new TestSupports;
        $support->seed();

        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)
            ->get('/admin/server/security/scans')
            ->assertForbidden();
    }

    public function test_admin_can_view_scans_index()
    {
        $user = $this->adminUser();

        SecurityScan::create([
            'org_server_id' => 1,
            'tool' => 'kube-hunter',
            'status' => 'complete',
            'summary' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0],
        ]);

        $this->actingAs($user)
            ->get('/admin/server/security/scans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Security/ScansList')
                ->has('scans', 1)
            );
    }

    public function test_scans_index_filters_by_tool_and_date_range()
    {
        $user = $this->adminUser();

        $matching = SecurityScan::create([
            'org_server_id' => 1,
            'tool' => 'trivy',
            'status' => 'complete',
        ]);
        $matching->created_at = now()->subDays(2);
        $matching->save();

        SecurityScan::create([
            'org_server_id' => 1,
            'tool' => 'kube-bench',
            'status' => 'complete',
        ]);

        $this->actingAs($user)
            ->get('/admin/server/security/scans?'.http_build_query([
                'tool' => 'trivy',
                'date_from' => now()->subDays(5)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Security/ScansList')
                ->has('scans', 1)
                ->where('scans.0.tool', 'trivy')
            );
    }

    public function test_admin_can_view_a_scan_report_with_findings()
    {
        $user = $this->adminUser();

        $scan = SecurityScan::create([
            'org_server_id' => 1,
            'tool' => 'kube-bench',
            'status' => 'complete',
            'raw_output' => '{}',
        ]);

        SecurityFinding::create([
            'security_scan_id' => $scan->id,
            'severity' => 'high',
            'title' => 'Anonymous auth enabled',
            'category' => 'Control Plane',
            'resource_type' => 'Node',
            'resource_name' => 'node-1',
            'description' => 'desc',
            'remediation' => 'disable it',
            'metadata' => ['test_number' => '1.2.1'],
        ]);

        $this->actingAs($user)
            ->get("/admin/server/security/scans/{$scan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Security/ScanDetail')
                ->where('scan.tool', 'kube-bench')
                ->has('scan.findings', 1)
                ->where('scan.findings.0.resource_type', 'Node')
                ->where('scan.findings.0.resource_name', 'node-1')
                ->where('scan.findings.0.metadata', ['test_number' => '1.2.1'])
                ->where('scan.findings.0.resolved', false)
                ->where('scan.findings_count', 1)
                ->where('scan.resolved_findings_count', 0)
            );
    }

    public function test_admin_can_resolve_and_unresolve_a_finding()
    {
        $user = $this->adminUser();

        $scan = SecurityScan::create(['org_server_id' => 1, 'tool' => 'kube-bench', 'status' => 'complete']);
        $finding = SecurityFinding::create([
            'security_scan_id' => $scan->id,
            'severity' => 'high',
            'title' => 'Anonymous auth enabled',
        ]);

        $this->actingAs($user)
            ->patch("/admin/server/security/scans/{$scan->id}/findings/{$finding->id}", ['resolved' => true])
            ->assertOk()
            ->assertJson(['id' => $finding->id, 'resolved' => true]);

        $this->assertDatabaseHas('security_findings', ['id' => $finding->id]);
        $this->assertTrue($finding->fresh()->isResolved());

        $this->actingAs($user)
            ->patch("/admin/server/security/scans/{$scan->id}/findings/{$finding->id}", ['resolved' => false])
            ->assertOk()
            ->assertJson(['id' => $finding->id, 'resolved' => false]);

        $this->assertFalse($finding->fresh()->isResolved());
    }

    public function test_resolving_a_finding_that_does_not_belong_to_the_scan_is_rejected()
    {
        $user = $this->adminUser();

        $scan = SecurityScan::create(['org_server_id' => 1, 'tool' => 'kube-bench', 'status' => 'complete']);
        $other_scan = SecurityScan::create(['org_server_id' => 1, 'tool' => 'trivy', 'status' => 'complete']);
        $finding = SecurityFinding::create([
            'security_scan_id' => $other_scan->id,
            'severity' => 'high',
            'title' => 'Belongs to the other scan',
        ]);

        $this->actingAs($user)
            ->patch("/admin/server/security/scans/{$scan->id}/findings/{$finding->id}", ['resolved' => true])
            ->assertNotFound();
    }

    public function test_scans_index_reports_findings_and_resolved_counts()
    {
        $user = $this->adminUser();

        $scan = SecurityScan::create(['org_server_id' => 1, 'tool' => 'kube-bench', 'status' => 'complete']);
        SecurityFinding::create(['security_scan_id' => $scan->id, 'severity' => 'high', 'title' => 'A', 'resolved_at' => now()]);
        SecurityFinding::create(['security_scan_id' => $scan->id, 'severity' => 'low', 'title' => 'B']);

        $this->actingAs($user)
            ->get('/admin/server/security/scans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Security/ScansList')
                ->where('scans.0.findings_count', 2)
                ->where('scans.0.resolved_findings_count', 1)
            );
    }

    public function test_admin_can_delete_a_scan()
    {
        $user = $this->adminUser();

        $scan = SecurityScan::create([
            'org_server_id' => 1,
            'tool' => 'kube-bench',
            'status' => 'complete',
        ]);

        $this->actingAs($user)
            ->delete("/admin/server/security/scans/{$scan->id}")
            ->assertRedirect('/admin/server/security/scans');

        $this->assertDatabaseMissing('security_scans', ['id' => $scan->id]);
    }

    public function test_running_nuclei_without_targets_is_rejected()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans', [
                'org_server_id' => 1,
                'tool' => 'nuclei',
            ])
            ->assertSessionHasErrors('targets');

        $this->assertDatabaseCount('security_scans', 0);
    }

    public function test_running_trivy_with_an_invalid_severity_is_rejected()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans', [
                'org_server_id' => 1,
                'tool' => 'trivy',
                'severity' => ['NOT-A-REAL-SEVERITY'],
            ])
            ->assertSessionHasErrors('severity.0');

        $this->assertDatabaseCount('security_scans', 0);
    }

    public function test_running_trivy_without_any_severity_is_rejected()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans', [
                'org_server_id' => 1,
                'tool' => 'trivy',
            ])
            ->assertSessionHasErrors('severity');

        $this->assertDatabaseCount('security_scans', 0);
    }

    public function test_running_kube_bench_without_a_severity_is_allowed()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans', [
                'org_server_id' => 1,
                'tool' => 'kube-bench',
            ])
            ->assertSessionDoesntHaveErrors('severity');

        $this->assertDatabaseCount('security_scans', 1);
    }

    public function test_scans_index_exposes_severity_filter_support()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->get('/admin/server/security/scans')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Security/ScansList')
                ->where('tools_supporting_severity_filter', ['trivy'])
                ->where('tools_supporting_namespace_filter', ['trivy'])
                ->where('severities', ['UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])
            );
    }

    private function makeOrgServer(): \App\OrgServer
    {
        $organization = \App\Organization::factory()->create();

        \Illuminate\Support\Facades\DB::table('org_servers')->insert([
            'organization_id' => $organization->id,
            'server_id' => 1,
        ]);

        return \App\OrgServer::where('organization_id', $organization->id)->firstOrFail();
    }

    private function makeAppInstance(array $attributes): AppInstance
    {
        $application = \App\Application::factory()->create();

        $app = new AppInstance;
        $app->forceFill(array_merge([
            'api_password' => '',
            'status' => 'active',
            'application_id' => $application->id,
            'version_id' => \App\AppVersion::factory()->create(['application_id' => $application->id])->id,
        ], $attributes));
        $app->save();

        return $app;
    }

    public function test_apps_endpoint_lists_app_instances_for_the_organization()
    {
        $user = $this->adminUser();
        $org_server = $this->makeOrgServer();
        $other_org_server = $this->makeOrgServer();

        $app = $this->makeAppInstance(['organization_id' => $org_server->organization_id, 'name' => 'my-app', 'label' => 'My App']);
        $this->makeAppInstance(['organization_id' => $other_org_server->organization_id, 'name' => 'other-org-app', 'label' => 'Other Org App']);

        $this->actingAs($user)
            ->get("/admin/server/security/scans/apps?org_server_id={$org_server->id}")
            ->assertOk()
            ->assertJson([
                ['value' => $app->id, 'text' => 'My App'],
            ]);
    }

    public function test_app_domains_endpoint_only_lists_app_type_domains()
    {
        $user = $this->adminUser();
        $org_server = $this->makeOrgServer();

        $app = $this->makeAppInstance(['organization_id' => $org_server->organization_id, 'name' => 'my-app', 'label' => 'My App']);
        $appDomain = OrgDomain::factory()->create([
            'organization_id' => $org_server->organization_id,
            'app_instance_id' => $app->id,
            'type' => 'app',
            'name' => 'app.example.com',
        ]);
        OrgDomain::factory()->create([
            'organization_id' => $org_server->organization_id,
            'app_instance_id' => $app->id,
            'type' => 'connection',
            'name' => 'connection.example.com',
        ]);

        $this->actingAs($user)
            ->get("/admin/server/security/scans/apps/{$app->id}/domains")
            ->assertOk()
            ->assertExactJson([
                ['value' => $appDomain->name, 'text' => $appDomain->name],
            ]);
    }

    public function test_custom_domains_can_be_saved_and_listed_per_organization()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans/custom-domains', [
                'organization_id' => 1,
                'domain' => 'saved.example.com',
            ])
            ->assertOk();

        $this->assertDatabaseHas('security_scan_saved_values', [
            'organization_id' => 1,
            'type' => SecurityScanSavedValue::TYPE_DOMAIN,
            'value' => 'saved.example.com',
        ]);

        $this->actingAs($user)
            ->get('/admin/server/security/scans/custom-domains?organization_id=1')
            ->assertOk()
            ->assertExactJson([
                ['value' => 'saved.example.com', 'text' => 'saved.example.com'],
            ]);
    }

    public function test_saving_the_same_custom_domain_twice_does_not_duplicate_it()
    {
        $user = $this->adminUser();

        SecurityScanSavedValue::create([
            'organization_id' => 1,
            'type' => SecurityScanSavedValue::TYPE_DOMAIN,
            'value' => 'saved.example.com',
        ]);

        $this->actingAs($user)
            ->post('/admin/server/security/scans/custom-domains', [
                'organization_id' => 1,
                'domain' => 'saved.example.com',
            ])
            ->assertOk();

        $this->assertDatabaseCount('security_scan_saved_values', 1);
    }

    public function test_custom_namespaces_can_be_saved_and_listed_per_organization()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans/custom-namespaces', [
                'organization_id' => 1,
                'namespace' => 'kube-system',
            ])
            ->assertOk();

        $this->assertDatabaseHas('security_scan_saved_values', [
            'organization_id' => 1,
            'type' => SecurityScanSavedValue::TYPE_NAMESPACE,
            'value' => 'kube-system',
        ]);

        $this->actingAs($user)
            ->get('/admin/server/security/scans/custom-namespaces?organization_id=1')
            ->assertOk()
            ->assertExactJson([
                ['value' => 'kube-system', 'text' => 'kube-system'],
            ]);
    }

    public function test_a_domain_and_a_namespace_with_the_same_value_do_not_collide()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/server/security/scans/custom-domains', [
                'organization_id' => 1,
                'domain' => 'shared-name',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->post('/admin/server/security/scans/custom-namespaces', [
                'organization_id' => 1,
                'namespace' => 'shared-name',
            ])
            ->assertOk();

        $this->assertDatabaseCount('security_scan_saved_values', 2);
    }

}
