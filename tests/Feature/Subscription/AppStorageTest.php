<?php

namespace Tests\Feature\Subscription;

use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AppStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_instance_storage_calculation()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();
        $user = User::find(1);
        $this->actingAs($user);

        $demo_app = $support->demo_app->instances()->first();

        $support->setSubscription($user->organization, $support->base_2, $support->demo_app_2, $demo_app);

        Application::roles($support->demo_app);
        $this->assertEquals(2, Application::instance($demo_app)->storage()->calculateTotalAppStorage());

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertEquals(4, Application::instance($demo_app)->storage()->calculateTotalAppStorage());

        $edit1 = $this->put('/users/testing1', [
            'first_name' => 'test',
            'last_name' => 'user1',
            'personal_email' => 'test1@example.com',
            'organization' => $user->organization->id,
            'additional_storage' => [
                $demo_app->id => 2,
            ],
        ]);

        $this->assertEquals(8, Application::instance($demo_app)->storage()->calculateTotalAppStorage());
        $this->assertEquals(8, Application::instance($demo_app)->storage()->totalAppStorage());
    }

    public function test_app_standard_basic_user_storage()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $support->addUsers();
        $support->disableApps();

        $this->withoutExceptionHandling();
        $user = User::find(1);
        $this->actingAs($user);
        // $this->followingRedirects();

        $demo_app = $support->demo_app->instances()->first();

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_1, $demo_app);
        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertEquals(1, AccountManager::users()->find('testing1')->appStorage($demo_app));

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['basic_demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);

        $this->assertEquals(0.5, AccountManager::users()->find('testing1')->appStorage($demo_app));

        $support->setSubscription($user->organization, $support->base_1, $support->demo_app_2, $demo_app);
        $this->assertEquals(1, AccountManager::users()->find('testing1')->appStorage($demo_app));

        $permission1 = $this->post('/users/testing1/permissions', [
            'permission' => [
                $demo_app->id => ['demo_role'],
                'control_panel' => false,
                'control_panel_admin' => false,
            ],
        ]);
        $this->assertEquals(2, AccountManager::users()->find('testing1')->appStorage($demo_app));
    }
}
