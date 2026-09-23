<?php

use App\Organization;
use App\Support\Facades\AccountManager;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    setupAccountManagerDriver('db');
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
});

it('allows any authenticated member of an active organization to manage groups', function () {
    $user = User::where('username', 'testing1')->firstOrFail();
    $user->email_verified_at = now();
    $user->save();
    $this->actingAs($user);

    $this->post('/groups', ['name' => 'member-group', 'category' => 'others'])
        ->assertRedirectContains('member-group');

    expect(AccountManager::groups()->find('member-group'))->not->toBeNull();

    $this->delete('/groups/member-group')->assertRedirect('/groups');

    expect(AccountManager::groups()->find('member-group'))->toBeNull();
});

it('denies group creation/deletion when the organization is deactivated', function () {
    $existing = AccountManager::groups()->add(['name' => 'existing-group', 'category' => 'others']);

    $organization = Organization::find(1);
    $organization->status = 'deactivated';
    $organization->save();

    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $this->post('/groups', ['name' => 'blocked-group', 'category' => 'others'])->assertForbidden();
    expect(AccountManager::groups()->find('blocked-group'))->toBeNull();

    $this->delete('/groups/existing-group')->assertForbidden();
    expect(AccountManager::groups()->find('existing-group'))->not->toBeNull();
});
