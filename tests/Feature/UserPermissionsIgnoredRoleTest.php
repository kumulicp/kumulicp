<?php

use App\AppInstance;
use App\AppRole;
use App\Jobs\Applications\AddLdapGroups;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->support = new TestSupports;
    $this->support->seed();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());
    $this->support->activateDemoApp();
    $this->support->addUsers();
    $this->instance = $this->support->demo_app->instances()->where('organization_id', 1)->first();

    // The role picked is ignored itself, and gives the role it implies
    $this->role = AppRole::where('slug', 'demo_role')->first();
    $this->implied = AppRole::where('slug', 'minimal_demo_role')->first();
    $this->role->implied_roles()->sync([$this->implied->id]);
    $this->role->ignore_role = true;
    $this->role->save();
    (new AddLdapGroups($this->instance))->handle();
});

afterEach(function () {
    $this->support->cleanLdap();
});

function groupsOf(string $username): array
{
    return collect(AccountManager::users()->find($username)->get()->groups()->get())->map(fn ($group) => $group->getDn())->all();
}

it('keeps a role that implies others, and the ones it implies, after the permissions are saved', function () {
    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->post('/users/testing1/permissions', ['permission' => [$this->instance->id => ['demo_role']]])
        ->assertRedirect();

    $groups = groupsOf('testing1');

    expect($groups)->toHaveCount(2)
        ->and(collect($groups)->contains(fn ($dn) => str_contains($dn, '-demo_role,')))->toBeTrue()
        ->and(collect($groups)->contains(fn ($dn) => str_contains($dn, '-minimal_demo_role,')))->toBeTrue();
});

it('shows the role that was picked, not one of the roles it implies', function () {
    $this->actingAs(User::where('username', 'demo')->firstOrFail())
        ->post('/users/testing1/permissions', ['permission' => [$this->instance->id => ['demo_role']]]);

    $page = $this->actingAs(User::where('username', 'demo')->firstOrFail())->get('/users/testing1/permissions');
    $app = collect($page->viewData('page')['props']['permissions'])->firstWhere('id', $this->instance->id);

    expect($app['categories'][0]['active_role'])->toBe('Demo Role');
});

it('takes the roles away again when none is picked', function () {
    $user = $this->actingAs(User::where('username', 'demo')->firstOrFail());
    $user->post('/users/testing1/permissions', ['permission' => [$this->instance->id => ['demo_role']]]);

    $user->post('/users/testing1/permissions', ['permission' => [$this->instance->id => ['none']]]);

    expect(groupsOf('testing1'))->toBe([]);
});

// A multisite app (ERPNext) keeps its own role groups for each site, so a site's permissions are
// written to, and have to be read from, the site's own group, not its shared app's
describe('a site of a multisite shared app', function () {
    beforeEach(function () {
        // The same app, but one that says it is multisite (registering another profile for it would be ignored)
        $profile = Application::profile('demo_app');
        $compatibility = new ReflectionProperty($profile, 'compatibility');
        $compatibility->setValue($profile, ['multisite']);

        $this->hub = $this->instance;
        $this->site = AppInstance::factory()->create([
            'application_id' => $this->hub->application_id,
            'organization_id' => $this->hub->organization_id,
            'version_id' => $this->hub->version_id,
            'plan_id' => $this->hub->plan_id,
            'parent_id' => $this->hub->id,
            'status' => 'active',
        ]);
        (new AddLdapGroups($this->site))->handle();

        // Not ignored: what is saved is the role itself
        $this->role->ignore_role = false;
        $this->role->save();
        $this->role->implied_roles()->sync([]);
    });

    it('sees the role it saved on the site, not the shared app', function () {
        $user = AccountManager::users()->find('testing1');
        $user->permissions()->updateAppRoles($this->site, [$this->role]);

        $user = AccountManager::users()->find('testing1');

        expect($user->hasAppRole($this->site, $this->role))->toBeTrue()
            ->and($user->hasAppRole($this->hub, $this->role))->toBeFalse()
            ->and($user->appUserAccessType($this->site))->toBe('standard');
    });
});
