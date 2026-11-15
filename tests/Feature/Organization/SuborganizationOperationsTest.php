<?php

use App\AppInstance;
use App\Organization;
use App\OrgDomain;
use App\SuborgUser;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use App\User;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

/**
 * Boots the suborganization test fixture for the given account manager driver.
 * Must run before any AccountManager calls, since setupAccountManagerDriver()
 * has to be applied before TestSupports::seed() reads the env-driven config.
 */
function setupSuborganizationTest(string $driver): array
{
    setupAccountManagerDriver($driver);

    $test = test();
    $test->setupFakeServerInterfaces();
    $test->fakeNotificationsAndMail();

    $support = new TestSupports;
    $support->seed();
    $support->createBase2Plan();

    Config::set('toggle.flags.sub-organizations', true);

    $organization = Organization::find(1);
    $organization->plan->updateSettings(['suborganizations.enabled' => true]);
    $organization->plan->save();

    $user = User::where('username', 'demo')->firstOrFail();
    $test->actingAs($user);

    $suborganization = Organization::factory()->create([
        'slug' => 'suborgops',
        'parent_organization_id' => $organization->id,
        'plan_id' => $organization->plan_id,
    ]);

    $test->support = $support;

    return compact('support', 'organization', 'user', 'suborganization');
}

/**
 * Verifies a user's effective organization, abstracting over how each driver
 * represents suborg scoping: the 'db' driver moves organization_id directly,
 * while 'ldap' leaves the LDAP entry's own org untouched and tracks scoping
 * via a SuborgUser row instead.
 */
function expectUserOrganization(string $username, Organization $expectedOrganization, Organization $topLevelOrganization): void
{
    if (config('account_manager.driver') === 'ldap') {
        $suborgUser = SuborgUser::where('username', $username)->first();

        if ($expectedOrganization->is($topLevelOrganization)) {
            expect($suborgUser)->toBeNull();
        } else {
            expect($suborgUser)->not->toBeNull();
            expect($suborgUser->organization_id)->toBe($expectedOrganization->id);
        }

        return;
    }

    $user = User::where('username', $username)->firstOrFail();
    expect($user->organization_id)->toBe($expectedOrganization->id);
}

afterEach(function () {
    $this->support?->cleanLdap();
});

it('moves a user to a specific suborganization', function (string $driver) {
    ['organization' => $organization, 'suborganization' => $suborganization] = setupSuborganizationTest($driver);

    AccountManager::users()->add([
        'username' => 'subuser1',
        'first_name' => 'Sub',
        'last_name' => 'User',
        'name' => 'Sub User',
        'email' => 'subuser1@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);

    $response = $this->put('/users/subuser1', [
        'first_name' => 'Sub',
        'last_name' => 'User',
        'personal_email' => 'subuser1@example.com',
        'phone_number' => '1234567890',
        'organization' => $suborganization->id,
    ]);

    $response->assertSessionHasNoErrors();

    expectUserOrganization('subuser1', $suborganization, $organization);
})->with('account_manager_drivers');

it('does not allow moving a user to an unrelated organization', function (string $driver) {
    ['organization' => $organization] = setupSuborganizationTest($driver);

    $unrelated = Organization::factory()->create(['slug' => 'unrelated-ops']);

    AccountManager::users()->add([
        'username' => 'subuser2',
        'first_name' => 'Sub',
        'last_name' => 'User',
        'name' => 'Sub User',
        'email' => 'subuser2@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);

    $response = $this->put('/users/subuser2', [
        'first_name' => 'Sub',
        'last_name' => 'User',
        'personal_email' => 'subuser2@example.com',
        'phone_number' => '1234567890',
        'organization' => $unrelated->id,
    ]);

    $response->assertSessionHasErrors('organization');

    expectUserOrganization('subuser2', $organization, $organization);
})->with('account_manager_drivers');

it('creates a suborg user record when a user is added while acting in a suborganization', function (string $driver) {
    ['user' => $user, 'suborganization' => $suborganization] = setupSuborganizationTest($driver);

    $user->organization_id = $suborganization->id;
    $user->save();
    $user->load('organization');

    AccountManager::users()->add([
        'username' => 'subuser3',
        'first_name' => 'Sub',
        'last_name' => 'User',
        'name' => 'Sub User',
        'email' => 'subuser3@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);

    $this->post('/users', [
        'username' => 'subuser4',
        'first_name' => 'Sub',
        'last_name' => 'User',
        'personal_email' => 'subuser4@example.com',
    ])->assertSessionHasNoErrors();

    expect(SuborgUser::where('organization_id', $suborganization->id)->where('username', 'subuser4')->exists())->toBeTrue();
})->with('account_manager_drivers');

it('grants a user control panel admin access scoped to a specific suborganization', function (string $driver) {
    ['organization' => $organization, 'suborganization' => $suborganization] = setupSuborganizationTest($driver);

    AccountManager::users()->add([
        'username' => 'subadmin1',
        'first_name' => 'Sub',
        'last_name' => 'Admin',
        'name' => 'Sub Admin',
        'email' => 'subadmin1@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);

    $response = $this->post('/users/subadmin1/permissions', [
        'permission' => [
            'control_panel' => [$suborganization->id],
            'control_panel_admin' => ['control_panel_standard'],
        ],
    ]);

    $response->assertSessionHasNoErrors();

    $userManager = AccountManager::users()->find('subadmin1');

    // Granting control panel access provisions/updates a database-backed
    // user record directly scoped to the suborganization, for both drivers.
    expect($userManager->databaseUser()->organization_id)->toBe($suborganization->id);
    expect($userManager->permissions()->hasControlPanelAdminAccess())->toBeTrue();
})->with('account_manager_drivers');

it('rejects scoping control panel access to an unrelated organization', function (string $driver) {
    setupSuborganizationTest($driver);

    $unrelated = Organization::factory()->create(['slug' => 'unrelated-ops2']);

    AccountManager::users()->add([
        'username' => 'subadmin2',
        'first_name' => 'Sub',
        'last_name' => 'Admin',
        'name' => 'Sub Admin',
        'email' => 'subadmin2@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);

    $response = $this->post('/users/subadmin2/permissions', [
        'permission' => [
            'control_panel' => [$unrelated->id],
        ],
    ]);

    $response->assertSessionHasErrors('permission.control_panel.0');
})->with('account_manager_drivers');

it('adds a domain to a specific suborganization', function (string $driver) {
    ['support' => $support, 'user' => $user, 'suborganization' => $suborganization] = setupSuborganizationTest($driver);

    $suborganization->plan_id = $support->base_2->id;
    $suborganization->save();

    $user->organization_id = $suborganization->id;
    $user->save();
    $user->load('organization');
    OrganizationFacade::setOrganization($suborganization);

    $response = $this->post('/settings/domains/connect', [
        'domain_name' => 'example.com',
    ]);

    $response->assertSessionHasNoErrors();

    $domain = OrgDomain::where('name', 'example.com')->first();

    expect($domain)->not->toBeNull();
    expect($domain->organization_id)->toBe($suborganization->id);
})->with('account_manager_drivers');

it('activates an app for a specific suborganization', function (string $driver) {
    ['support' => $support, 'suborganization' => $suborganization] = setupSuborganizationTest($driver);

    $prepared = $support->prepareDemoApp();

    $this->runActivate($prepared['plan'], $suborganization, $prepared['app']);

    expect(AppInstance::where('organization_id', $suborganization->id)
        ->where('application_id', $prepared['app']->id)
        ->where('status', 'active')
        ->exists())->toBeTrue();
})->with('account_manager_drivers');
