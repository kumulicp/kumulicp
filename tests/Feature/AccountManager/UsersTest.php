<?php

use App\Support\Facades\AccountManager;
use App\User;
use Tests\Support\TestSupports;

it('can add a user', function (string $driver) {
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $response = $this->post('/users', [
        'username' => 'newuser',
        'first_name' => 'New',
        'last_name' => 'User',
        'personal_email' => 'newuser@example.com',
        'phone_number' => '1234567890',
    ]);

    $response->assertRedirect('/users/newuser/permissions');
    $user = AccountManager::users()->find('newuser');
    expect($user)->not->toBeNull();
    expect($user->attribute('phone_number'))->toBe('1234567890');
})->with('account_manager_drivers');

it('can update a user', function (string $driver) {
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $response = $this->put('/users/testing1', [
        'first_name' => 'Updated',
        'last_name' => 'Name',
        'personal_email' => 'updated@example.com',
        'phone_number' => '0987654321',
        'organization' => $admin->organization_id,
    ]);

    $response->assertRedirect('/users/testing1');
    $user = AccountManager::users()->find('testing1');
    expect($user->attribute('first_name'))->toBe('Updated');
    expect($user->attribute('last_name'))->toBe('Name');
    expect($user->attribute('email'))->toBe('updated@example.com');
    expect($user->attribute('phone_number'))->toBe('0987654321');
})->with('account_manager_drivers');

it('can delete a user', function (string $driver) {
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $response = $this->delete('/users/testing1');

    $response->assertRedirect('/users');
    expect(AccountManager::users()->find('testing1'))->toBeNull();
})->with('account_manager_drivers');
