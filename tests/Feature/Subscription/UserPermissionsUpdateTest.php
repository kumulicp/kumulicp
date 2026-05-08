<?php

namespace Tests\Feature\Subscription;

use App\AppInstance;
use App\AppPlan;
use App\Organization;
use App\Plan;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class UserPermissionsUpdateTest extends TestCase
{
    use RefreshDatabase;

    private TestSupports $support;

    private User $adminUser;

    private AppInstance $wordpressInstance;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = new TestSupports;
        $this->support->seed();
        $this->support->addUsers();

        // The demo user (id:1) acts as the admin performing permission updates
        $this->adminUser = User::find(1);
        $this->adminUser->assignRole('control_panel_admin');
        $this->adminUser->assignRole('organization_admin');
        $this->adminUser->is_allowed = true;
        $this->adminUser->save();

        // Wordpress app instance (id:2) from seeder has roles defined on its version
        $this->wordpressInstance = AppInstance::find(2);
        $this->organization = Organization::find(1);

        // Initialize the subscription so Subscription::base() is available in the controller
        $this->support->setSubscription(
            $this->organization,
            Plan::find(1),
            AppPlan::find(3),
            $this->wordpressInstance
        );

        $this->actingAs($this->adminUser);
    }

    public function test_update_app_permissions_grants_role_on_app_instance()
    {
        $user = AccountManager::users()->find('testing1');

        $this->assertFalse($user->canAccessApp($this->wordpressInstance));

        $this->post('/users/testing1/permissions', [
            'permission' => [
                $this->wordpressInstance->id => ['administrator'],
                'control_panel' => ['none'],
                'control_panel_admin' => ['none'],
            ],
        ])->assertRedirect();

        $this->assertTrue($user->canAccessApp($this->wordpressInstance));
        $this->assertEquals('standard', $user->appUserAccessType($this->wordpressInstance));
    }

    public function test_update_app_permissions_assigns_basic_role()
    {
        $user = AccountManager::users()->find('testing1');

        $this->post('/users/testing1/permissions', [
            'permission' => [
                $this->wordpressInstance->id => ['author'],
                'control_panel' => ['none'],
                'control_panel_admin' => ['none'],
            ],
        ])->assertRedirect();

        $this->assertTrue($user->canAccessApp($this->wordpressInstance));
        $this->assertEquals('basic', $user->appUserAccessType($this->wordpressInstance));
    }

    public function test_update_organization_permissions_grants_control_panel_access()
    {
        $user = AccountManager::users()->find('testing1');

        $this->assertFalse($user->permissions()->hasControlPanelAccess());

        $this->post('/users/testing1/permissions', [
            'permission' => [
                'control_panel' => [$this->organization->id],
                'control_panel_admin' => ['none'],
            ],
        ])->assertRedirect();

        $dbUser = $user->databaseUser();
        $this->assertTrue((bool) $dbUser->is_allowed);
        $this->assertTrue($dbUser->hasRole('organization_admin'));
    }

    public function test_update_organization_permissions_removes_control_panel_access()
    {
        $user = AccountManager::users()->find('testing1');

        // First grant access
        $this->post('/users/testing1/permissions', [
            'permission' => [
                'control_panel' => [$this->organization->id],
                'control_panel_admin' => ['none'],
            ],
        ]);

        $this->assertTrue((bool) $user->databaseUser()->is_allowed);

        // Now revoke it
        $this->post('/users/testing1/permissions', [
            'permission' => [
                'control_panel' => ['none'],
                'control_panel_admin' => ['none'],
            ],
        ])->assertRedirect();

        $dbUser = $user->databaseUser()->fresh();
        $this->assertFalse((bool) $dbUser->is_allowed);
        $this->assertFalse($dbUser->hasRole('organization_admin'));
    }

    public function test_update_control_panel_admin_permissions_grants_admin_role()
    {
        $user = AccountManager::users()->find('testing1');

        $this->assertFalse($user->permissions()->hasControlPanelAdminAccess());

        // Grant org access first, then grant admin
        $this->post('/users/testing1/permissions', [
            'permission' => [
                'control_panel' => [$this->organization->id],
                'control_panel_admin' => ['control_panel_standard'],
            ],
        ])->assertRedirect();

        $dbUser = $user->databaseUser()->fresh();
        $this->assertTrue($dbUser->hasRole('control_panel_admin'));
    }

    public function test_update_control_panel_admin_permissions_removes_admin_role()
    {
        $user = AccountManager::users()->find('testing1');

        // Grant admin first
        $this->post('/users/testing1/permissions', [
            'permission' => [
                'control_panel' => [$this->organization->id],
                'control_panel_admin' => ['control_panel_standard'],
            ],
        ]);

        $this->assertTrue($user->databaseUser()->fresh()->hasRole('control_panel_admin'));

        // Revoke admin
        $this->post('/users/testing1/permissions', [
            'permission' => [
                'control_panel' => [$this->organization->id],
                'control_panel_admin' => ['none'],
            ],
        ])->assertRedirect();

        $this->assertFalse($user->databaseUser()->fresh()->hasRole('control_panel_admin'));
    }
}
