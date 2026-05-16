<?php

use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

it('user groups page loads', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    Queue::fake();
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $group_name = fake()->unique()->word;
    $this->post('/groups', ['name' => $group_name, 'category' => 'others']);

    $this->withoutExceptionHandling();
    $response = $this->get('/users/demo/groups');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Organization/Users/UserGroups')
        ->has('user')
        ->has('groups')
    );
})->with('account_manager_drivers');

it('user groups page includes current group memberships', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    Queue::fake();
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $group_name = fake()->unique()->word;
    $this->post('/groups', ['name' => $group_name, 'category' => 'others']);
    $this->get('/users/demo/groups/'.$group_name.'/add');

    $response = $this->get('/users/demo/groups');

    $response->assertInertia(fn ($page) => $page
        ->component('Organization/Users/UserGroups')
        ->where('user.groups', fn ($groups) => collect($groups)->pluck('slug')->contains($group_name))
    );
})->with('account_manager_drivers');

it('can add a user to a group', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    Queue::fake();
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $group_name = fake()->unique()->word;
    $this->post('/groups', ['name' => $group_name, 'category' => 'others']);

    $this->withoutExceptionHandling();
    $response = $this->get('/users/demo/groups/'.$group_name.'/add');

    $response->assertRedirect('/users/demo/groups');
    expect(AccountManager::users()->find('demo')->listGroups()->pluck('slug')->all())
        ->toContain($group_name);
})->with('account_manager_drivers');

it('can remove a user from a group', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    Queue::fake();
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $group_name = fake()->unique()->word;
    $this->post('/groups', ['name' => $group_name, 'category' => 'others']);
    $this->get('/users/demo/groups/'.$group_name.'/add');

    $this->withoutExceptionHandling();
    $response = $this->get('/users/demo/groups/'.$group_name.'/remove');

    $response->assertRedirect('/users/demo/groups');
    expect(AccountManager::users()->find('demo')->listGroups()->pluck('slug')->all())
        ->not->toContain($group_name);
})->with('account_manager_drivers');

it('add and remove do not affect other group memberships', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    Queue::fake();
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $this->withoutExceptionHandling();

    $group_name = fake()->unique()->word;
    $other_group_name = fake()->unique()->word;
    $this->post('/groups', ['name' => $group_name, 'category' => 'others']);
    $this->post('/groups', ['name' => $other_group_name, 'category' => 'others']);

    $this->get('/users/demo/groups/'.$other_group_name.'/add');
    $this->get('/users/demo/groups/'.$group_name.'/add');
    $this->get('/users/demo/groups/'.$group_name.'/remove');

    $slugs = AccountManager::users()->find('demo')->listGroups()->pluck('slug')->all();
    expect($slugs)->not->toContain($group_name);
    expect($slugs)->toContain($other_group_name);
})->with('account_manager_drivers');
