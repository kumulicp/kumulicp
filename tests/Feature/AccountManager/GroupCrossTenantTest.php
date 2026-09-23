<?php

use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    setupAccountManagerDriver('db');
    (new TestSupports)->seed();
    OrganizationFacade::setOrganization(Organization::find(1));
});

it('does not attach a user from another organization as group manager or member', function () {
    $otherOrgUser = User::factory()->create(['username' => 'other-org-user']);

    $group = AccountManager::accounts()->groups()->add(['name' => 'cross-tenant-group', 'category' => 'others']);

    $group->updateMembers([$otherOrgUser->username]);
    $group->updateManagers([$otherOrgUser->username]);

    expect($group->members()->all())->not->toContain($otherOrgUser->username)
        ->and($group->managerNames()->all())->not->toContain($otherOrgUser->username);
});

it('skips a nonexistent username without throwing', function () {
    $group = AccountManager::accounts()->groups()->add(['name' => 'skip-missing-user-group', 'category' => 'others']);

    $group->updateMembers(['this-username-does-not-exist']);
    $group->updateManagers(['this-username-does-not-exist']);

    expect($group->members()->all())->toBeEmpty()
        ->and($group->managerNames()->all())->toBeEmpty();
});
