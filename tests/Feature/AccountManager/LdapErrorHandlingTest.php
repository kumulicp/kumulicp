<?php

use App\Exceptions\AccountManagerException;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Support\Str;
use Tests\Support\TestSupports;

beforeEach(function () {
    setupAccountManagerDriver('ldap');
    (new TestSupports)->seed();
});

it('returns a graceful error instead of a 500 when group creation fails', function () {
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    breakLdapConnection();

    $response = $this->post('/groups', ['name' => 'ldap-err-'.Str::random(8), 'category' => 'others']);

    restoreLdapConnection();

    $response->assertStatus(302);
    $response->assertSessionHas('error');
});

it('returns a graceful error instead of a 500 when updating a group fails', function () {
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $name = 'ldap-err-'.Str::random(8);
    $this->post('/groups', ['name' => $name, 'category' => 'others'])->assertRedirectContains($name);

    breakLdapConnection();

    $response = $this->put('/groups/'.$name, [
        'original_name' => $name,
        'name' => 'renamed-while-ldap-down',
        'category' => 'others',
        'managers' => [],
        'members' => [],
    ]);

    restoreLdapConnection();

    $response->assertStatus(302);
    $response->assertSessionHas('error');

    AccountManager::groups()->find($name)?->delete();
});

it('returns a graceful error instead of a 500 when granting control panel access fails', function () {
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $username = 'permstarget'.Str::random(8);
    AccountManager::users()->add([
        'username' => $username,
        'first_name' => 'Perms',
        'last_name' => 'Target',
        'name' => 'Perms Target',
        'email' => $username.'@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);

    breakLdapConnection();

    $response = $this->post("/users/{$username}/permissions", [
        'permission' => [
            'control_panel' => [1],
        ],
    ]);

    restoreLdapConnection();

    $response->assertStatus(302);
    $response->assertSessionHas('error');
});

it('throws AccountManagerException instead of a raw LdapRecordException when provisioning a new organization fails', function () {
    $organization = Organization::factory()->create(['slug' => 'ldap-fail-'.Str::random(8)]);

    breakLdapConnection();

    try {
        expect(fn () => AccountManager::accounts()->create($organization))
            ->toThrow(AccountManagerException::class);
    } finally {
        restoreLdapConnection();
    }
});

it('throws AccountManagerException instead of a raw LdapRecordException when destroying an organization account fails', function () {
    $organization = Organization::find(1);

    breakLdapConnection();

    try {
        expect(fn () => AccountManager::account($organization)->destroy())
            ->toThrow(AccountManagerException::class);
    } finally {
        restoreLdapConnection();
    }
});
