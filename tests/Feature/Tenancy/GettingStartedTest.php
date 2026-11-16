<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Server;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The seeded database has a default web server and a base domain, the demo organization is a
// superaccount, and the demo user is an admin. Nothing is ready or installed yet.

beforeEach(function () {
    (new TestSupports)->seed();
});

function startedAdmin(): User
{
    return User::where('username', 'demo')->firstOrFail();
}

/**
 * An app that is ready, with one instance installed for the demo organization.
 */
function startedInstalledApp(): void
{
    $app = Application::factory()->create(['slug' => 'started-app']);
    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $plan = AppPlan::factory()->create(['application_id' => $app->id]);

    AppInstance::factory()->create([
        'organization_id' => 1,
        'application_id' => $app->id,
        'version_id' => $version->id,
        'plan_id' => $plan->id,
        'label' => 'Started app',
    ]);
}

it('walks an admin through setting up, showing what is done and what is not', function () {
    useSingleTenancy();

    $this->actingAs(startedAdmin())
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('getting_started', function ($steps) {
            $steps = collect($steps)->keyBy('key');

            return $steps->keys()->all() === ['servers', 'app_ready', 'domain', 'app_installed', 'sso']
                && $steps['servers']['done'] === true
                && $steps['domain']['done'] === true
                && $steps['app_ready']['done'] === false
                && $steps['app_installed']['done'] === false
                && $steps['sso']['done'] === false
                && $steps['sso']['optional'] === true
                && $steps['servers']['optional'] === false
                && $steps['app_ready']['url'] === '/admin/apps'
                && $steps['app_installed']['url'] === '/discover';
        }));
});

it('notices when there is no server to run apps on', function () {
    useSingleTenancy();
    Server::query()->update(['default_web_server' => 0]);

    $this->actingAs(startedAdmin())
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('getting_started', fn ($steps) => collect($steps)->firstWhere('key', 'servers')['done'] === false));
});

it('stops showing the checklist once everything required is done, even without single sign-on', function () {
    useSingleTenancy();
    startedInstalledApp();

    $this->actingAs(startedAdmin())
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('getting_started', null));
});

it('does not show the checklist to a member', function () {
    useSingleTenancy();
    AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

    $this->actingAs(startedAdmin())
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('getting_started', null));
});

it('does not show the checklist in multi-tenant mode', function () {
    useMultiTenancy();

    $this->actingAs(startedAdmin())
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('getting_started', null));
});

it('leaves the organization and plan widgets off the admin overview in single mode', function () {
    useSingleTenancy();

    $this->actingAs(startedAdmin())
        ->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('organizations', null)
            ->where('plans', null)
            ->has('applications')
            ->has('stats'));
});

it('keeps them in multi-tenant mode', function () {
    useMultiTenancy();

    $this->actingAs(startedAdmin())
        ->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('organizations.total', 1)
            ->has('plans'));
});
