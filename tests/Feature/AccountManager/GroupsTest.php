<?php

use App\Support\Facades\AccountManager;
use App\User;
use Tests\Support\TestSupports;

it('creates, edits, and deletes a group', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->addUsers();

    $this->withoutExceptionHandling();
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $name = fake()->word;

    $new_group = $this->post('/groups', [
        'name' => $name,
        'category' => 'others',
    ]);

    $new_group->assertRedirectContains($name);

    $new_name = fake()->word;
    $edit = $this->put('/groups/'.$name, [
        'original_name' => $name,
        'name' => $new_name,
        'category' => 'others',
        'managers' => ['demo', 'testing1'],
        'members' => ['testing2'],
    ]);

    $edit->assertValid(['original_name', 'name', 'category', 'managers', 'members']);

    $group = AccountManager::accounts($user->organization)->groups()->find($new_name);
    $members = $group->members();
    $managers = $group->managerNames()->all();
    expect(in_array('demo', $members))->toBeTrue();
    expect(in_array('testing1', $members))->toBeTrue();
    expect(in_array('testing2', $members))->toBeTrue();
    expect(in_array('demo', $managers))->toBeTrue();
    expect(in_array('testing1', $managers))->toBeTrue();
    expect(in_array('testing2', $managers))->toBeFalse();

    $this->delete('/groups/'.$new_name);

    expect(AccountManager::accounts($user->organization)->groups()->find($new_name))->toBeNull();
})->with('account_manager_drivers');
