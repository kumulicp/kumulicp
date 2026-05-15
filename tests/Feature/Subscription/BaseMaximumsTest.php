<?php

use App\Support\Facades\AccountManager;

it('enforces max standard user limit', function () {
    skipUnlessDriver('ldap');
    $this->withoutExceptionHandling();
    $this->followingRedirects();

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

    expect(AccountManager::users()->find('testing1')->canAccessApp($this->demoApp))->toBeFalse();
    expect(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp))->toBeFalse();

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    grantPermission('testing2', $this->demoApp->id, ['demo_role']);

    expect(AccountManager::users()->find('testing1')->canAccessApp($this->demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp))->toBeFalse();

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

    grantPermission('testing2', $this->demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp))->toBeTrue();
});

it('enforces max additional storage limit', function () {
    skipUnlessDriver('ldap');
    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

    grantPermission('testing1', $this->demoApp->id, ['demo_role']);
    setAdditionalStorage('testing1', $this->demoApp->id, 1);
    expect(AccountManager::users()->find('testing1')->appStorage($this->demoApp))->toBe(4);

    grantPermission('testing2', $this->demoApp->id, ['demo_role']);
    setAdditionalStorage('testing2', $this->demoApp->id, 1);
    expect(AccountManager::users()->find('testing2')->appStorage($this->demoApp))->toBe(4);

    setAdditionalStorage('testing2', $this->demoApp->id, 100);
    expect(AccountManager::users()->find('testing2')->appStorage($this->demoApp))->toBe(4);
});

it('enforces max basic user limit', function () {
    skipUnlessDriver('ldap');
    $this->withoutExceptionHandling();
    $this->followingRedirects();

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_1, $this->demoApp);

    grantPermission('testing1', $this->demoApp->id, ['basic_demo_role']);
    grantPermission('testing2', $this->demoApp->id, ['basic_demo_role']);

    expect(AccountManager::users()->find('testing1')->canAccessApp($this->demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing1')->appUserAccessType($this->demoApp))->toBe('basic');
    expect(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp))->toBeFalse();
    expect(AccountManager::users()->find('testing2')->appUserAccessType($this->demoApp))->toBe('none');

    $this->support->setSubscription($this->user->organization, $this->support->base_1, $this->support->demo_app_2, $this->demoApp);

    grantPermission('testing2', $this->demoApp->id, ['basic_demo_role']);
    expect(AccountManager::users()->find('testing2')->canAccessApp($this->demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing2')->appUserAccessType($this->demoApp))->toBe('basic');
});

it('enforces max domains limit', function () {
    $this->followingRedirects();
    $this->user->organization->domains()->delete();

    $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

    $domain1 = $this->post('/settings/domains/connect', ['domain_name' => 'example1.com']);
    $domain1->assertSee('example1.com');

    $this->followingRedirects();
    $domain2 = $this->post('/settings/domains/connect', ['domain_name' => 'example2.com']);
    $domain2->assertSee('example2.com');

    $domain3 = $this->post('/settings/domains/connect', ['domain_name' => 'example3.com']);
    $domain3->assertStatus(403);
});
