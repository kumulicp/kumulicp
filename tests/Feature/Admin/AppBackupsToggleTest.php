<?php

namespace Tests\Feature\Admin;

use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AppBackupsToggleTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();

        return User::find(1);
    }

    public function test_backup_scheduler_routes_are_blocked_when_the_app_backups_flag_is_disabled()
    {
        $admin = $this->adminUser();
        config(['toggle.flags.app-backups' => false]);

        $this->actingAs($admin)->get('/admin/server/backup_scheduler')->assertNotFound();
    }

    public function test_backup_scheduler_routes_are_available_when_the_app_backups_flag_is_enabled()
    {
        $admin = $this->adminUser();
        config(['toggle.flags.app-backups' => true]);

        $this->actingAs($admin)->get('/admin/server/backup_scheduler')->assertOk();
    }

    public function test_organization_backups_routes_are_blocked_when_the_app_backups_flag_is_disabled()
    {
        $admin = $this->adminUser();
        config(['toggle.flags.app-backups' => false]);
        $organization = \App\Organization::factory()->create();

        $this->actingAs($admin)->get("/admin/organizations/{$organization->id}/backups")->assertNotFound();
    }
}
