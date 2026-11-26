<?php

/*
|--------------------------------------------------------------------------
| Groups across a parent organization and its suborganizations
|--------------------------------------------------------------------------
|
| Rules these tests pin down (docs/suborganizations-production-plan.md, 2.2):
|
|  - Groups are per organization. A suborganization only sees and manages its
|    own groups, never the parent's or a sibling's.
|  - The parent can see and manage its suborganizations' groups.
|  - A parent user can't belong to a suborg group. A suborg user can belong to
|    a parent group.
|  - The same group name can exist in two suborganizations.
|
| Several of these fail today on purpose: with the LDAP driver, a suborg and
| its parent share one groups tree, and with the DB driver the parent can't see
| its suborgs' groups. They should go green as the plan is implemented.
|
| Assertions only use the public AccountManager API and the /groups routes, so
| they don't depend on how the LDAP layout ends up.
|
*/

use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * Create a suborganization of org 1 with one user in it.
 *
 * @return array{0: Organization, 1: User}
 */
function suborgGroupsMakeSuborg(string $slug, string $username): array
{
    $parent = Organization::find(1);

    $suborg = Organization::factory()->create([
        'plan_id' => $parent->plan_id,
        'parent_organization_id' => $parent->id,
        'slug' => $slug,
        'name' => ucfirst($slug),
        'type' => $parent->type,
        'settings' => ['step' => 4, 'include_in_parent_invoice' => true],
    ]);

    OrganizationFacade::setOrganization($suborg);

    $manager = AccountManager::users()->add([
        'username' => $username,
        'first_name' => 'sub',
        'last_name' => $username,
        'name' => 'sub '.$username,
        'email' => $username.'@example.com',
        'password' => 'password',
        'phone_number' => '1234567890',
    ]);
    $manager->updateOrganization($suborg);

    // The LDAP driver keeps the user in the directory only, the login needs a row too.
    $user = User::where('username', $username)->first()
        ?? User::factory()->create(['username' => $username, 'organization_id' => $suborg->id]);
    $user->organization_id = $suborg->id;
    $user->email_verified_at = now();
    $user->save();

    OrganizationFacade::setOrganization($parent);

    return [$suborg, $user->fresh()];
}

/**
 * Seed org 1 (the parent, user "demo") and two suborgs, "suba" and "subb".
 *
 * @return array{0: User, 1: User, 2: User}  parent admin, suba user, subb user
 */
function suborgGroupsSetup(string $driver): array
{
    setupAccountManagerDriver($driver);
    Queue::fake();

    (new TestSupports)->seed();

    [, $user_a] = suborgGroupsMakeSuborg('suba', 'suba-user');
    [, $user_b] = suborgGroupsMakeSuborg('subb', 'subb-user');

    return [User::where('username', 'demo')->firstOrFail(), $user_a, $user_b];
}

/** Act as a user, with that user's organization as the active one. */
function suborgGroupsActAs($test, User $user): void
{
    OrganizationFacade::setOrganization($user->organization);
    $test->actingAs($user);
}

function suborgGroupsCreate($test, User $user, string $name): void
{
    suborgGroupsActAs($test, $user);
    $test->post('/groups', ['name' => $name, 'category' => 'others'])->assertSessionHasNoErrors();
}

function suborgGroupsFind($test, User $user, string $name)
{
    suborgGroupsActAs($test, $user);

    return AccountManager::groups()->find($name);
}

function suborgGroupsSetMembers($test, User $user, string $name, array $members, array $managers = []): void
{
    suborgGroupsActAs($test, $user);
    $test->put('/groups/'.$name, [
        'original_name' => $name,
        'name' => $name,
        'category' => 'others',
        'managers' => $managers,
        'members' => $members,
    ]);
}

// Scope: who sees what

it('keeps a suborg group out of a sibling suborg', function (string $driver) {
    [, $user_a, $user_b] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'alpha-group');

    expect(suborgGroupsFind($this, $user_b, 'alpha-group'))->toBeNull();
    expect(suborgGroupsFind($this, $user_a, 'alpha-group'))->not->toBeNull();
})->with('account_manager_drivers');

it('does not let a suborg delete a sibling suborg group', function (string $driver) {
    [, $user_a, $user_b] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'alpha-group');

    suborgGroupsActAs($this, $user_b);
    $this->delete('/groups/alpha-group');

    expect(suborgGroupsFind($this, $user_a, 'alpha-group'))->not->toBeNull();
})->with('account_manager_drivers');

it('keeps a parent group out of its suborgs', function (string $driver) {
    [$parent, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $parent, 'parent-group');

    expect(suborgGroupsFind($this, $user_a, 'parent-group'))->toBeNull();
    expect(suborgGroupsFind($this, $parent, 'parent-group'))->not->toBeNull();
})->with('account_manager_drivers');

it('does not let a suborg delete a parent group', function (string $driver) {
    [$parent, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $parent, 'parent-group');

    suborgGroupsActAs($this, $user_a);
    $this->delete('/groups/parent-group');

    expect(suborgGroupsFind($this, $parent, 'parent-group'))->not->toBeNull();
})->with('account_manager_drivers');

it('lets the parent see its suborgs groups', function (string $driver) {
    [$parent, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'alpha-group');

    suborgGroupsActAs($this, $parent);
    $this->get('/groups')->assertInertia(fn ($page) => $page
        ->component('Organization/Groups/GroupsList')
        ->where('categories', fn ($categories) => str_contains(json_encode($categories), 'alpha-group'))
    );
})->with('account_manager_drivers');

it('lets the parent delete a suborg group', function (string $driver) {
    [$parent, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'alpha-group');

    suborgGroupsActAs($this, $parent);
    $this->delete('/groups/alpha-group');

    expect(suborgGroupsFind($this, $user_a, 'alpha-group'))->toBeNull();
})->with('account_manager_drivers');

// Names

it('allows the same group name in two suborgs', function (string $driver) {
    [, $user_a, $user_b] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'shared-name');
    suborgGroupsCreate($this, $user_b, 'shared-name');

    expect(suborgGroupsFind($this, $user_a, 'shared-name'))->not->toBeNull();
    expect(suborgGroupsFind($this, $user_b, 'shared-name'))->not->toBeNull();
})->with('account_manager_drivers');

it('allows a parent group to reuse a name a suborg already has', function (string $driver) {
    [$parent, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'shared-name');
    suborgGroupsCreate($this, $parent, 'shared-name');

    expect(suborgGroupsFind($this, $parent, 'shared-name'))->not->toBeNull();
})->with('account_manager_drivers');

// Membership

it('rejects a parent user as a member or manager of a suborg group', function (string $driver) {
    [, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'alpha-group');
    // "suba-user" is valid, "demo" belongs to the parent and isn't.
    suborgGroupsSetMembers($this, $user_a, 'alpha-group', ['suba-user', 'demo'], ['demo']);

    $group = suborgGroupsFind($this, $user_a, 'alpha-group');

    expect(collect($group->members())->contains('suba-user'))->toBeTrue()
        ->and(collect($group->members())->contains('demo'))->toBeFalse()
        ->and(collect($group->managerNames())->contains('demo'))->toBeFalse();
})->with('account_manager_drivers');

it('rejects a sibling suborg user as a member of a suborg group', function (string $driver) {
    [, $user_a] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $user_a, 'alpha-group');
    suborgGroupsSetMembers($this, $user_a, 'alpha-group', ['suba-user', 'subb-user']);

    $group = suborgGroupsFind($this, $user_a, 'alpha-group');

    expect(collect($group->members())->contains('suba-user'))->toBeTrue()
        ->and(collect($group->members())->contains('subb-user'))->toBeFalse();
})->with('account_manager_drivers');

it('lets a parent group contain suborg users', function (string $driver) {
    [$parent] = suborgGroupsSetup($driver);

    suborgGroupsCreate($this, $parent, 'parent-group');
    suborgGroupsSetMembers($this, $parent, 'parent-group', ['demo', 'suba-user']);

    $group = suborgGroupsFind($this, $parent, 'parent-group');

    expect(collect($group->members())->contains('demo'))->toBeTrue()
        ->and(collect($group->members())->contains('suba-user'))->toBeTrue();
})->with('account_manager_drivers');
