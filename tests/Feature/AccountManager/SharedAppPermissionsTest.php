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
 * their roles in groups scoped to the org, kept separately for each of the org's instances on it. Either way, what is saved has to be what is read back.
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

    it('keeps the instance\'s role groups under its own name, not the shared app\'s', function () {
        expect(roleGroupExists($this->child, $this->child->name, $this->role))->toBeTrue()
            ->and(roleGroupExists($this->child, $this->hub->name, $this->role))->toBeFalse();
    });

    it('keeps the permissions of two instances on the same shared app, in the same org, apart', function () {
        $second = AppInstance::factory()->create([
            'application_id' => $this->child->application_id,
            'organization_id' => $this->child->organization_id,
            'version_id' => $this->child->version_id,
            'plan_id' => $this->child->plan_id,
            'parent_id' => $this->hub->id,
            'status' => 'active',
        ]);
        (new AddLdapGroups($second))->handle();

        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($second, [$this->role]);

        $user = AccountManager::users()->find('testing1');
        expect($user->hasAppRole($second, $this->role))->toBeTrue()
            ->and($user->appUserAccessType($second))->toBe('standard')
            ->and($user->hasAppRole($this->child, $this->role))->toBeFalse()
            ->and($user->appUserAccessType($this->child))->toBe('none');

        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($this->child, [$this->role]);
        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($second, []);

        $user = AccountManager::users()->find('testing1');
        expect($user->hasAppRole($this->child, $this->role))->toBeTrue()
            ->and($user->hasAppRole($second, $this->role))->toBeFalse();
    });

    it('keeps the permissions of an instance that was shared but whose plan no longer is apart from one that never was', function () {
        $separate = AppInstance::factory()->create([
            'application_id' => $this->hub->application_id,
            'organization_id' => $this->hub->organization_id,
            'version_id' => $this->hub->version_id,
            'plan_id' => $this->hub->plan_id,
            'status' => 'active',
        ]);
        (new AddLdapGroups($separate))->handle();

        // The plan is changed from shared to separate after the child was created: its parent link remains
        $plan = $this->child->plan;
        $plan->settings = array_merge($plan->settings, ['server_type' => 'separate']);
        $plan->save();
        $this->child->refresh();

        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($separate, [$this->role]);

        $user = AccountManager::users()->find('testing1');
        expect($user->hasAppRole($separate, $this->role))->toBeTrue()
            ->and($user->hasAppRole($this->child, $this->role))->toBeFalse()
            ->and($user->appUserAccessType($this->child))->toBe('none');

        AccountManager::users()->find('testing1')->permissions()->updateAppRoles($this->child, [$this->role]);

        $user = AccountManager::users()->find('testing1');
        expect($user->hasAppRole($this->child, $this->role))->toBeTrue()
            ->and($user->appUserAccessType($this->child))->toBe('standard');
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

        expect(roleGroupExists($this->child, $this->child->name, $this->role))->toBeTrue()
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
