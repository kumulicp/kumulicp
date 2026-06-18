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

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();

    $this->support = new TestSupports;
    $this->support->seed();
    $this->support->createBase2Plan();

    Config::set('toggle.flags.sub-organizations', true);

    $this->organization = Organization::find(1);
    $this->organization->plan->updateSettings(['suborganizations.enabled' => true]);
    $this->organization->plan->save();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);

    $this->suborganization = \App\Organization::factory()->create([
        'slug' => 'suborgops',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);
});

it('moves a user to a specific suborganization', function () {
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
        'organization' => $this->suborganization->id,
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('username', 'subuser1')->firstOrFail();
    expect($user->organization_id)->toBe($this->suborganization->id);
});

it('does not allow moving a user to an unrelated organization', function () {
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

    $user = User::where('username', 'subuser2')->firstOrFail();
    expect($user->organization_id)->toBe($this->organization->id);
});

it('creates a suborg user record when a user is added while acting in a suborganization', function () {
    $this->user->organization_id = $this->suborganization->id;
    $this->user->save();
    $this->user->load('organization');

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

    expect(SuborgUser::where('organization_id', $this->suborganization->id)->where('username', 'subuser4')->exists())->toBeTrue();
});

it('grants a user control panel admin access scoped to a specific suborganization', function () {
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
            'control_panel' => [$this->suborganization->id],
            'control_panel_admin' => ['control_panel_standard'],
        ],
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('username', 'subadmin1')->firstOrFail();
    expect($user->organization_id)->toBe($this->suborganization->id);

    $userManager = AccountManager::users()->find('subadmin1');
    expect($userManager->permissions()->hasControlPanelAdminAccess())->toBeTrue();
});

it('rejects scoping control panel access to an unrelated organization', function () {
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
});

it('adds a domain to a specific suborganization', function () {
    $this->suborganization->plan_id = $this->support->base_2->id;
    $this->suborganization->save();

    $this->user->organization_id = $this->suborganization->id;
    $this->user->save();
    $this->user->load('organization');
    OrganizationFacade::setOrganization($this->suborganization);

    $response = $this->post('/settings/domains/connect', [
        'domain_name' => 'example.com',
    ]);

    $response->assertSessionHasNoErrors();

    $domain = OrgDomain::where('name', 'example.com')->first();

    expect($domain)->not->toBeNull();
    expect($domain->organization_id)->toBe($this->suborganization->id);
});

it('activates an app for a specific suborganization', function () {
    $prepared = $this->support->prepareDemoApp();

    $this->runActivate($prepared['plan'], $this->suborganization, $prepared['app']);

    expect(AppInstance::where('organization_id', $this->suborganization->id)
        ->where('application_id', $prepared['app']->id)
        ->where('status', 'active')
        ->exists())->toBeTrue();
});
