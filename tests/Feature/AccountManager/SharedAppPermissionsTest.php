<?php

use App\AppInstance;
use App\AppPlan;
use App\AppRole;
use App\Jobs\Applications\AddLdapGroups;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;
use App\User;
use Tests\Support\TestSupports;

/**
 * A shared app has two shapes. A multisite app (ERPNext) gives each org a site of its own, with
 * its own role groups. A centralized app (Nextcloud) is one app for every org: an org's users get
 * their roles in groups under the shared app's name, scoped to the org, and the org's instance
 * has no groups of its own. Either way, what is saved has to be what is read back.
 */
beforeEach(function () {
    setupAccountManagerDriver('ldap');
    $this->support = new TestSupports;
    $this->support->seed();
    $this->actingAs(User::where('username', 'demo')->firstOrFail());
    $this->support->activateDemoApp();
    $this->support->addUsers();

    $this->hub = $this->support->demo_app->instances()->where('organization_id', 1)->first();
    $this->role = AppRole::where('slug', 'demo_role')->first();

    $shared_plan = AppPlan::factory()->create([
        'application_id' => $this->hub->application_id,
        'web_server_id' => 1,
        'shared_app_id' => $this->hub->id,
        'settings' => array_merge($this->hub->plan->settings ?? [], ['server_type' => 'shared']),
    ]);

    $this->child = AppInstance::factory()->create([
        'application_id' => $this->hub->application_id,
        'organization_id' => $this->hub->organization_id,
        'version_id' => $this->hub->version_id,
        'plan_id' => $shared_plan->id,
        'parent_id' => $this->hub->id,
        'status' => 'active',
    ]);
});

afterEach(function () {
    $this->support->cleanLdap();
});

function setMultisite(bool $multisite): void
{
    // The same app, but one that says it is multisite (registering another profile for it would be ignored)
    $profile = Application::profile('demo_app');
    (new ReflectionProperty($profile, 'compatibility'))->setValue($profile, $multisite ? ['multisite'] : ['shareable']);
}

function roleGroupExists(AppInstance $instance, string $container, AppRole $role, ?AppInstance $slug_of = null): bool
{
    return Group::find(Dn::create($instance->organization, 'applications', [$role->app_slug($slug_of ?? $instance), $container])) !== null;
}

function usernamesOf(iterable $users): array
{
    return collect($users)->map(fn ($user) => $user->attribute('username'))->all();
}

describe('a centralized shared app (Nextcloud)', function () {
    beforeEach(function () {
        setMultisite(false);
        (new AddLdapGroups($this->child))->handle();
    });

    it('keeps the org\'s role groups under the shared app\'s name, and none under its own', function () {
        expect(roleGroupExists($this->child, $this->hub->name, $this->role, $this->hub))->toBeTrue()
            ->and(Group::find(Dn::create($this->child->organization, 'applications', $this->child->name)))->toBeNull();
    });

    it('sees the role it saved, and the access it gives', function () {
        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($this->child, [$this->role]);

        $user = AccountManager::users()->find('testing1');

        expect($user->hasAppRole($this->child, $this->role))->toBeTrue()
            ->and($user->appUserAccessType($this->child))->toBe('standard')
            ->and($user->canAccessApp($this->child))->toBeTrue();
    });

    it('lists the user among the instance\'s users', function () {
        $permissions = AccountManager::users()->find('testing1')->permissions();
        $permissions->updateAppRoles($this->child, [$this->role]);
        $permissions->updateUserAccessType(); // As saving permissions does; the standard users are the ones of that type

        $standard = collect(AccountManager::users()->appStandardUsers($this->child))->map(fn ($user) => $user->getFirstAttribute('cn'))->all();

        expect(usernamesOf(AccountManager::users()->appUsers($this->child)))->toContain('testing1')
            ->and($standard)->toContain('testing1');
    });

    it('saves what the permissions page posts for the instance, and shows it again', function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail())
            ->post('/users/testing1/permissions', ['permission' => [$this->child->id => ['demo_role']]])
            ->assertRedirect();

        expect(AccountManager::users()->find('testing1')->hasAppRole($this->child, $this->role))->toBeTrue();

        $page = $this->actingAs(User::where('username', 'demo')->firstOrFail())->get('/users/testing1/permissions');
        $app = collect($page->viewData('page')['props']['permissions'])->firstWhere('id', $this->child->id);

        expect($app['categories'][0]['active_role'])->toBe($this->role->name);
    });

    it('takes the role away again when none is picked', function () {
        $user = $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $user->post('/users/testing1/permissions', ['permission' => [$this->child->id => ['demo_role']]]);
        $user->post('/users/testing1/permissions', ['permission' => [$this->child->id => ['none']]]);

        expect(AccountManager::users()->find('testing1')->hasAppRole($this->child, $this->role))->toBeFalse();
    });
});

describe('a centralized shared app in another org than its hub', function () {
    beforeEach(function () {
        setMultisite(false);

        // The usual case: the hub is the server's, and the child belongs to a customer's own org, with
        // its own users and groups
        $this->other_org = Organization::factory()->create(['slug' => 'testing', 'name' => 'Testing', 'plan_id' => 1]);
        AccountManager::accounts()->create($this->other_org);
        AccountManager::users($this->other_org)->add([
            'username' => 'customer1',
            'first_name' => 'customer',
            'last_name' => 'user1',
            'name' => 'customer user1',
            'email' => 'customer1@example.com',
            'password' => 'password',
            'phone_number' => '1234567890',
        ]);
        $this->child->organization()->associate($this->other_org)->save();
        $this->child->refresh();

        (new AddLdapGroups($this->child))->handle();
    });

    it('sees the role it saved in the child\'s org, and the access it gives', function () {
        AccountManager::users($this->other_org)->find('customer1')->permissions()->updateAppRoles($this->child, [$this->role]);

        $user = AccountManager::users($this->other_org)->find('customer1');

        expect(roleGroupExists($this->child, $this->hub->name, $this->role, $this->hub))->toBeTrue()
            ->and($user->hasAppRole($this->child, $this->role))->toBeTrue()
            ->and($user->appUserAccessType($this->child))->toBe('standard');
    });
});

describe('a multisite shared app (ERPNext)', function () {
    beforeEach(function () {
        setMultisite(true);
        (new AddLdapGroups($this->child))->handle();
    });

    afterEach(function () {
        setMultisite(false);
    });

    it('keeps the site\'s role groups under its own name', function () {
        expect(roleGroupExists($this->child, $this->child->name, $this->role))->toBeTrue();
    });

    it('sees the role it saved on the site, and the access it gives', function () {
        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($this->child, [$this->role]);

        $user = AccountManager::users()->find('testing1');

        expect($user->hasAppRole($this->child, $this->role))->toBeTrue()
            ->and($user->hasAppRole($this->hub, $this->role))->toBeFalse()
            ->and($user->appUserAccessType($this->child))->toBe('standard')
            ->and($user->canAccessApp($this->child))->toBeTrue()
            ->and($user->canAccessApp($this->hub))->toBeFalse();
    });

    it('lists the user among the site\'s users only', function () {
        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($this->child, [$this->role]);

        expect(usernamesOf(AccountManager::users()->appUsers($this->child)))->toContain('testing1')
            ->and(usernamesOf(AccountManager::users()->appUsers($this->hub)))->not->toContain('testing1');
    });
});
