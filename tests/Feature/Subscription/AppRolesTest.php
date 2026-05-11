<?php

namespace Tests\Feature\Subscription;

use App\Jobs\Applications\UpdateLdapGroups;
use App\Support\Facades\AccountManager;

class AppRolesTest extends SubscriptionTestCase
{
    public function test_app_role_access_types()
    {
        $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);
        UpdateLdapGroups::dispatch($this->demoApp);

        $user = AccountManager::users()->find('testing1');
        $this->assertFalse($user->canAccessApp($this->demoApp));

        $this->grantPermission('testing1', $this->demoApp->id, ['demo_role']);

        $this->assertTrue($user->canAccessApp($this->demoApp));
        $this->assertEquals('standard', $user->appUserAccessType($this->demoApp));
    }
}
