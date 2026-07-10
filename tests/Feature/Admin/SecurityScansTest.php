<?php

namespace Tests\Feature\Admin;

use App\SecurityFinding;
use App\SecurityScan;
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
            'description' => 'desc',
            'remediation' => 'disable it',
        ]);

        $this->actingAs($user)
            ->get("/admin/server/security/scans/{$scan->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Security/ScanDetail')
                ->where('scan.tool', 'kube-bench')
                ->has('scan.findings', 1)
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
}
