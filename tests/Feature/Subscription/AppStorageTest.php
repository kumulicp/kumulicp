<?php

namespace Tests\Feature\Subscription;

use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;

class AppStorageTest extends SubscriptionTestCase
{
    public function test_app_instance_storage_calculation()
    {
        $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

        Application::roles($this->support->demo_app);
        $this->assertEquals(2, Application::instance($this->demoApp)->storage()->calculateTotalAppStorage());

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);
        $this->assertEquals(4, Application::instance($this->demoApp)->storage()->calculateTotalAppStorage());

        $this->setAdditionalStorage('testing1', $this->demoApp->id, 2);

        $this->assertEquals(8, Application::instance($this->demoApp)->storage()->calculateTotalAppStorage());
        $this->assertEquals(8, Application::instance($this->demoApp)->storage()->totalAppStorage());
    }

    public function test_app_standard_basic_user_storage()
    {
        $this->withoutExceptionHandling();

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);
        $this->assertEquals(1, AccountManager::users()->find('testing1')->appStorage($this->demoApp));

        $this->grantPermission('testing1', $this->demoApp->id, ['basic_demo_role']);
        $this->assertEquals(0.5, AccountManager::users()->find('testing1')->appStorage($this->demoApp));

        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);
        $this->assertEquals(1, AccountManager::users()->find('testing1')->appStorage($this->demoApp));

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);
        $this->assertEquals(2, AccountManager::users()->find('testing1')->appStorage($this->demoApp));
    }
}
