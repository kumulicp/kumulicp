<?php

use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;

it('calculates total app instance storage', function () {
    skipUnlessDriver('ldap');
    $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

    Application::roles($this->support->demo_app);
    expect(Application::instance($this->demoApp)->storage()->calculateTotalAppStorage())->toBe(2);

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    expect(Application::instance($this->demoApp)->storage()->calculateTotalAppStorage())->toBe(4);

    setAdditionalStorage('testing1', $this->demoApp->id, 2);

    expect(Application::instance($this->demoApp)->storage()->calculateTotalAppStorage())->toBe(8);
    expect(Application::instance($this->demoApp)->storage()->totalAppStorage())->toBe(8);
});

it('calculates standard and basic user storage', function () {
    skipUnlessDriver('ldap');
    $this->withoutExceptionHandling();

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing1')->appStorage($this->demoApp))->toBe(1);

    grantPermission('testing1', $this->demoApp->id, ['basic_demo_role']);
    expect(AccountManager::users()->find('testing1')->appStorage($this->demoApp))->toBe(0.5);

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);
    expect(AccountManager::users()->find('testing1')->appStorage($this->demoApp))->toBe(1);

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing1')->appStorage($this->demoApp))->toBe(2);
});
