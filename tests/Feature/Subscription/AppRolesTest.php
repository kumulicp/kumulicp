<?php

use App\Jobs\Applications\UpdateLdapGroups;
use App\Support\Facades\AccountManager;

it('tracks app role access types', function () {
    skipUnlessDriver('ldap');
    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);
    UpdateLdapGroups::dispatch($this->demoApp);

    $user = AccountManager::users()->find('testing1');
    expect($user->canAccessApp($this->demoApp))->toBeFalse();

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);

    expect($user->canAccessApp($this->demoApp))->toBeTrue();
    expect($user->appUserAccessType($this->demoApp))->toBe('standard');
});
