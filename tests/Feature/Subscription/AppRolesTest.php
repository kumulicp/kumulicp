<?php

namespace Tests\Feature\Subscription;

use App\Jobs\Applications\UpdateLdapGroups;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AppRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_role_access_types()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        // $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);

        $demo_app = $support->demo_app->instances()->first();
        $support->demo_app->save();

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_1, $demo_app);
        UpdateLdapGroups::dispatch($demo_app);

        // Test standard role
        $user = AccountManager::users()->find('testing1');
        $this->assertFalse($user->canAccessApp($demo_app));
        $permission2 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertTrue($user->canAccessApp($demo_app));
        $this->assertEquals('standard', $user->appUserAccessType($demo_app));

        // Test basic roles
        /*$permission2 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $this->assertTrue($user->canAccessApp($demo_app));
        $this->assertEquals('basic', $user->appUserAccessType($demo_app));

        // Test minimal role
        $permission2 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['minimal_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertTrue($user->canAccessApp($demo_app));
        $this->assertEquals('minimal', $user->appUserAccessType($demo_app));*/
    }
}
