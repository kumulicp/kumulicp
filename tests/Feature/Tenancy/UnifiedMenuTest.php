<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A single-organization install has one menu: what a member sees, then an Administration section
// for an admin. The seeded demo organization is a superaccount and the demo user is an admin.
// Test requests carry no REQUEST_URI, which is what picks the admin or organization menu in a
// multi-tenant install, so the other mode always gets the organization menu here.

beforeEach(function () {
    (new TestSupports)->seed();
});

function unifiedUser(): User
{
    return User::where('username', 'demo')->firstOrFail();
}

function unifiedMakeNotAdmin(): void
{
    AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();
}

/**
 * Every url in the menu, submenus included.
 */
function unifiedUrls($menu): array
{
    return collect($menu)
        ->flatMap(fn ($item) => array_merge([$item['url'] ?? null], collect($item['submenu'] ?? [])->pluck('url')->all()))
        ->filter()
        ->values()
        ->all();
}

function unifiedPosition($menu, string $url): int|false
{
    return collect($menu)->search(fn ($item) => ($item['url'] ?? null) === $url);
}

it('gives an admin one menu with an Administration section after the organization items', function () {
    useSingleTenancy();

    $this->actingAs(unifiedUser())
        ->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
            $menu = collect($menu);
            $urls = unifiedUrls($menu);
            $header = $menu->search(fn ($item) => ($item['type'] ?? null) === 'header');

            return $header !== false
                && $menu[$header]['name'] === __('labels.administration')
                && collect(['/', '/apps', '/discover', '/users', '/groups'])->every(fn ($url) => in_array($url, $urls))
                && collect(['/admin', '/admin/apps', '/admin/server/servers', '/admin/server/tasks'])->every(fn ($url) => in_array($url, $urls))
                && unifiedPosition($menu, '/discover') < $header
                && unifiedPosition($menu, '/apps') < $header
                && unifiedPosition($menu, '/admin/apps') > $header;
        }));
});

it('names the admin items so they do not read like the organization ones', function () {
    useSingleTenancy();

    $this->actingAs(unifiedUser())
        ->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
            $menu = collect($menu);

            return $menu->firstWhere('url', '/')['name'] === __('labels.dashboard')
                && $menu->firstWhere('url', '/admin')['name'] === __('labels.overview')
                && $menu->firstWhere('url', '/apps')['name'] === __('labels.apps')
                && $menu->firstWhere('url', '/admin/apps')['name'] === __('labels.app_catalog');
        }));
});

it('keeps the same menu on an admin page', function () {
    useSingleTenancy();

    $this->actingAs(unifiedUser())
        ->get('/admin/apps')
        ->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
            $urls = unifiedUrls($menu);

            return in_array('/apps', $urls) && in_array('/discover', $urls) && in_array('/admin/apps', $urls);
        }));
});

it('shows a member Discover but no Administration section', function () {
    useSingleTenancy();
    unifiedMakeNotAdmin();

    $this->actingAs(unifiedUser())
        ->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
            $menu = collect($menu);
            $urls = unifiedUrls($menu);

            return in_array('/discover', $urls)
                && in_array('/apps', $urls)
                && $menu->search(fn ($item) => ($item['type'] ?? null) === 'header') === false
                && collect($urls)->filter(fn ($url) => str_starts_with($url, '/admin'))->isEmpty();
        }));
});

it('does not let an admin menu be served to a member of the same organization', function () {
    useSingleTenancy();
    $this->actingAs(unifiedUser());

    $this->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('menu', fn ($menu) => in_array('/admin/apps', unifiedUrls($menu))));

    unifiedMakeNotAdmin();

    $this->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('menu', fn ($menu) => ! in_array('/admin/apps', unifiedUrls($menu))));
});

it('leaves the multi-tenant menu as it was', function () {
    useMultiTenancy();

    $this->actingAs(unifiedUser())
        ->get('/settings/organization')
        ->assertInertia(fn ($page) => $page->where('menu', function ($menu) {
            $menu = collect($menu);
            $urls = unifiedUrls($menu);

            return in_array('/subscription', $urls)
                && ! in_array('/discover', $urls)
                && $menu->search(fn ($item) => ($item['type'] ?? null) === 'header') === false
                && collect($urls)->filter(fn ($url) => str_starts_with($url, '/admin'))->isEmpty();
        }));
});

function unifiedInstance(): AppInstance
{
    $app = Application::factory()->create(['slug' => 'menu-app', 'name' => 'Menu app']);
    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $plan = AppPlan::factory()->create(['application_id' => $app->id]);

    return AppInstance::factory()->create([
        'organization_id' => 1,
        'application_id' => $app->id,
        'version_id' => $version->id,
        'plan_id' => $plan->id,
        'label' => 'Menu app',
    ]);
}

it('lets an admin go from the org apps list to managing the app', function () {
    useSingleTenancy();
    $instance = unifiedInstance();

    $this->actingAs(unifiedUser())
        ->get('/apps')
        ->assertInertia(fn ($page) => $page->where('apps.0.manage_url', "/admin/organizations/1/apps/{$instance->id}"));
});

it('gives a member no link to manage an app', function () {
    useSingleTenancy();
    unifiedInstance();
    unifiedMakeNotAdmin();

    $this->actingAs(unifiedUser())
        ->get('/apps')
        ->assertInertia(fn ($page) => $page->where('apps.0.manage_url', null));
});

it('has no manage link on the org apps list in multi-tenant mode', function () {
    useMultiTenancy();
    unifiedInstance();

    $this->actingAs(unifiedUser())
        ->get('/apps')
        ->assertInertia(fn ($page) => $page->where('apps.0.manage_url', null));
});

it('links the admin app pages back to Discover in single mode only', function (string $page_path) {
    useSingleTenancy();
    Application::factory()->create(['slug' => 'menu-app']);
    $this->actingAs(unifiedUser());

    $this->get("/admin/apps/menu-app{$page_path}")
        ->assertInertia(fn ($page) => $page->where('discover_url', '/discover/menu-app'));

    useMultiTenancy();

    $this->get("/admin/apps/menu-app{$page_path}")
        ->assertInertia(fn ($page) => $page->where('discover_url', null));
})->with(['the app page' => '', 'the edit page' => '/edit']);
