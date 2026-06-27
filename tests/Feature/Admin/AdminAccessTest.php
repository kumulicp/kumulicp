<?php

namespace Tests\Feature\Admin;

use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_control_panel_access_but_not_admin_access_cannot_access_admin_routes(): void
    {
        $support = new TestSupports;
        $support->seed();

        $user = User::find(1);
        $permissions = AccountManager::users()->find('demo')->permissions();
        $this->assertTrue($permissions->hasControlPanelAdminAccess());
        $permissions->removeControlPanelAdminAccess();

        // Confirm the user still holds regular control panel access but not admin
        $this->assertTrue((bool) $permissions->hasControlPanelAccess());
        $this->assertFalse($permissions->hasControlPanelAdminAccess());

        $this->actingAs($user)
            ->get('/admin/service/plans')
            ->assertForbidden();
    }
}
