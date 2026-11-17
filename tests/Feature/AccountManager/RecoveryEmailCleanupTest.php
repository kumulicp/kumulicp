<?php

use App\Support\Facades\AccountManager;
use App\User;
use App\UserRecoveryEmail;
use Tests\Support\TestSupports;

it('removes a user\'s recovery email when the user is deleted', function (string $driver) {
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    UserRecoveryEmail::create(['username' => 'testing1', 'email' => 'testing1@gmail.com']);
    UserRecoveryEmail::create(['username' => 'testing2', 'email' => 'testing2@gmail.com']);

    AccountManager::users()->find('testing1')->delete();

    $this->assertDatabaseMissing('user_recovery_emails', ['username' => 'testing1']);
    $this->assertDatabaseHas('user_recovery_emails', ['username' => 'testing2']);
})->with('account_manager_drivers');
