<?php

use App\AppVersion;
use App\Console\Calls\CheckVersionUpdates;
use App\RepoSecret;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();

    Application::register(new DemoAppProfile);
    $this->demoApp = Application::initialize('demo_app');
    Application::roles($this->demoApp);

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
});

// ─── Store ────────────────────────────────────────────────────────────────────

it('creates a version with none — stores null settings', function () {
    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0',
        'copy_from' => 'none',
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/2.0");

    $version = AppVersion::where('application_id', $this->demoApp->id)
        ->where('name', '2.0')
        ->firstOrFail();

    expect($version->settings)->toBeNull();
    expect($version->admin_path)->toBeNull();
    expect($version->roles)->toBeNull();
    expect($version->status)->toBe('deactivated');
});

it('creates a version with recommendations — copies settings from app profile', function () {
    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0',
        'copy_from' => 'recommendations',
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/2.0");

    $version = AppVersion::where('application_id', $this->demoApp->id)
        ->where('name', '2.0')
        ->firstOrFail();

    expect($version->setting('chart_version'))->toBe('2.5.0');
    expect($version->setting('helm_repo_name'))->toBe('demo-chart');
    expect($version->setting('image_repo_name'))->toBe('demo/app');
    expect($version->setting('image_registry'))->toBe('registry.example.com');
    expect($version->admin_path)->toBe('/admin');
});

it('creates a version with previous_version — copies settings, roles and admin_path', function () {
    $roles = $this->demoApp->roles()->pluck('id')->toArray();

    $image_registry = RepoSecret::factory()->create(['registry' => 'registry3.example.com']);

    $source = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
        'admin_path' => '/source-admin',
        'image_registry_id' => $image_registry->id,
        'settings' => ['chart_version' => '1.2.3', 'helm_repo_name' => 'source-chart'],
        'roles' => ['order' => $roles, 'default_admin_groups' => [$roles[0]]],
    ]);

    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0',
        'copy_from' => 'previous_version',
        'copy_version' => $source->id,
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/2.0");

    $version = AppVersion::where('application_id', $this->demoApp->id)
        ->where('name', '2.0')
        ->firstOrFail();

    expect($version->setting('chart_version'))->toBe('1.2.3');
    expect($version->setting('helm_repo_name'))->toBe('source-chart');
    expect($version->admin_path)->toBe('/source-admin');
    expect($version->image_registry_id)->toBe($image_registry->id);
    expect($version->roles['order'])->toBe($roles);
    expect($version->roles['default_admin_groups'])->toBe([$roles[0]]);
});

// ─── Store validation ─────────────────────────────────────────────────────────

it('rejects a version name with a forward slash', function () {
    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0/rc1',
        'copy_from' => 'none',
    ]);

    $response->assertSessionHasErrors('version');
});

it('requires copy_version when copy_from is previous_version', function () {
    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0',
        'copy_from' => 'previous_version',
    ]);

    $response->assertSessionHasErrors('copy_version');
});

it('rejects an invalid copy_from value', function () {
    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0',
        'copy_from' => 'invalid',
    ]);

    $response->assertSessionHasErrors('copy_from');
});

// ─── Update ───────────────────────────────────────────────────────────────────

it('updates version settings and default roles', function () {
    $roles = $this->demoApp->roles()->pluck('id')->toArray();

    $image_registry = RepoSecret::factory()->create(['registry' => 'registry2.example.com']);

    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
        'roles' => ['order' => $roles],
    ]);

    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}", [
        'version' => '1.1',
        'admin_path' => '/new-admin',
        'chart_version' => '3.0.0',
        'helm_repo_name' => 'updated-chart',
        'image_repo_name' => 'updated/app',
        'image_registry_id' => $image_registry->id,
        'announcement_location' => 'none',
        'default_admin_roles' => [$roles[0]],
        'default_user_roles' => [$roles[1]],
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/1.1");

    $version->refresh();

    expect($version->name)->toBe('1.1');
    expect($version->admin_path)->toBe('/new-admin');
    expect($version->setting('chart_version'))->toBe('3.0.0');
    expect($version->setting('helm_repo_name'))->toBe('updated-chart');
    expect($version->setting('image_repo_name'))->toBe('updated/app');
    expect($version->image_registry_id)->toBe($image_registry->id);
    expect($version->imageRegistry->registry)->toBe('registry2.example.com');
    expect($version->roles['default_admin_groups'])->toBe([$roles[0]]);
    expect($version->roles['default_user_groups'])->toBe([$roles[1]]);
});

it('requires an image registry when updating a version', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
    ]);

    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}", [
        'version' => '1.0',
        'announcement_location' => 'none',
    ]);

    $response->assertSessionHasErrors('image_registry_id');
});

// ─── Roles (order) ───────────────────────────────────────────────────────────

it('saves role order — adds roles to selected list', function () {
    $roles = $this->demoApp->roles()->pluck('id')->toArray();

    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
        'roles' => null,
    ]);

    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}/roles", [
        'order' => $roles,
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}/roles");

    $version->refresh();
    expect($version->roles['order'])->toBe($roles);
});

it('saves role order — removes roles by submitting a subset', function () {
    $roles = $this->demoApp->roles()->pluck('id')->toArray();

    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
        'roles' => ['order' => $roles],
    ]);

    $kept = [$roles[0]];

    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}/roles", [
        'order' => $kept,
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}/roles");

    $version->refresh();
    expect($version->roles['order'])->toBe($kept);
});

it('saves role order — clears all roles when order is empty', function () {
    $roles = $this->demoApp->roles()->pluck('id')->toArray();

    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
        'roles' => ['order' => $roles],
    ]);

    $response = $this->post("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}/roles", [
        'order' => [],
    ]);

    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/{$version->name}/roles");

    $version->refresh();
    expect($version->roles['order'])->toBe([]);
});

// ─── Update check ─────────────────────────────────────────────────────────────

it('shows newer images and compatible charts on the versions page', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '33.0.1',
        'settings' => ['chart_version' => '9.0.0'],
        'update_check' => [
            'checked_at' => '2026-10-14T03:00:00+00:00',
            'image' => ['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null],
            'chart' => [
                'current' => '9.0.0',
                'latest' => '9.1.0',
                'latest_compatible' => '9.0.1',
                'compatibility' => ['version' => '9.1.0', 'status' => 'incompatible', 'issues' => ['The chart no longer has the value image.tag, which Kumulicp sets.'], 'warnings' => [], 'targets' => []],
                'error' => null,
            ],
        ],
    ]);

    $this->withoutVite()
        ->get("/admin/apps/{$this->demoApp->slug}/versions")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Applications/Versions/VersionsList')
            ->where('versions', fn ($versions) => collect($versions)->contains(fn ($row) => $row['id'] === $version->id
                && $row['updates']['image'] === '33.0.2'
                && $row['updates']['chart'] === '9.0.1'
                && $row['updates']['chart_incompatible']['version'] === '9.1.0'))
        );
});

it('hides stored updates once the version has caught up', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '33.0.2',
        'settings' => ['chart_version' => '9.0.1'],
        'update_check' => [
            'checked_at' => '2026-10-14T03:00:00+00:00',
            'image' => ['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null],
            'chart' => ['current' => '9.0.0', 'latest' => '9.0.1', 'latest_compatible' => '9.0.1', 'compatibility' => null, 'error' => null],
        ],
    ]);

    expect($version->newerImageTag())->toBeNull()
        ->and($version->newerChartVersion())->toBeNull()
        ->and($version->incompatibleChart())->toBeNull();
});

it('checks for updates on demand from the versions page', function () {
    AppVersion::where('application_id', $this->demoApp->id)->update(['status' => 'deactivated']);
    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0.0',
        'status' => 'active',
        'settings' => ['image_registry' => 'registry.example.com', 'image_repo_name' => 'demo/app'],
    ]);

    Http::fake(['registry.example.com/v2/demo/app/tags/list*' => Http::response(['tags' => ['1.0.0', '1.0.1', 'latest']])]);

    $this->post("/admin/apps/{$this->demoApp->slug}/versions/check-updates")
        ->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions");

    expect($version->refresh()->newerImageTag())->toBe('1.0.1')
        ->and($version->update_check['chart'])->toBeNull();
});

// ─── Update check: only enabled versions ──────────────────────────────────────

function checkedVersion(array $attributes = []): AppVersion
{
    return AppVersion::factory()->create(array_merge([
        'application_id' => test()->demoApp->id,
        'name' => '33.0.1',
        'status' => 'active',
        'settings' => ['chart_version' => '9.0.0'],
        'update_check' => [
            'checked_at' => '2026-10-14T03:00:00+00:00',
            'image' => ['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null],
            'chart' => ['current' => '9.0.0', 'latest' => '9.0.1', 'latest_compatible' => '9.0.1', 'compatibility' => null, 'error' => null],
        ],
    ], $attributes));
}

it('drops the update check when a version stops being enabled', function () {
    $version = checkedVersion();
    expect($version->refresh()->update_check)->not->toBeNull();

    $version->status = 'deactivated';
    $version->save();

    expect($version->refresh()->update_check)->toBeNull()
        ->and($version->updateSummary())->toBeNull();
});

it('only reports updates for the enabled version', function () {
    $old = checkedVersion();
    $old->status = 'deactivated';
    $old->save();
    $new = checkedVersion(['name' => '33.0.2']);

    $this->withoutVite()
        ->get("/admin/apps/{$this->demoApp->slug}/versions")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('versions', fn ($versions) => collect($versions)->where('updates', '!=', null)->pluck('id')->all() === [$new->id])
        );
});

it('clears results left on versions that are not enabled when checking for updates', function () {
    AppVersion::where('application_id', $this->demoApp->id)->update(['status' => 'deactivated']);
    $stale = checkedVersion(['status' => 'deactivated']);
    AppVersion::whereKey($stale->id)->update(['update_check' => json_encode(['checked_at' => '2026-10-14T03:00:00+00:00'])]);
    expect($stale->refresh()->update_check)->not->toBeNull();

    (new CheckVersionUpdates)();

    expect($stale->refresh()->update_check)->toBeNull();
});

it('adds a new version from an update, copying the checked version and its newest compatible chart', function () {
    $source = checkedVersion(['admin_path' => '/source-admin', 'settings' => ['chart_version' => '9.0.0', 'helm_repo_name' => 'source-chart']]);

    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '33.0.2',
        'copy_from' => 'update',
        'copy_version' => $source->id,
    ])->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/33.0.2");

    $version = AppVersion::where('application_id', $this->demoApp->id)->where('name', '33.0.2')->firstOrFail();

    expect($version->setting('chart_version'))->toBe('9.0.1')
        ->and($version->setting('helm_repo_name'))->toBe('source-chart')
        ->and($version->admin_path)->toBe('/source-admin')
        ->and($version->status)->toBe('deactivated')
        ->and($version->update_check)->toBeNull();
});

it('requires the version to copy when adding from an update', function () {
    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '33.0.2',
        'copy_from' => 'update',
    ])->assertSessionHasErrors('copy_version');
});

it('lists the enabled version, its chart and its updates on the admin application list', function () {
    $version = checkedVersion();

    $this->withoutVite()
        ->get('/admin/apps')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Applications/AppsList')
            ->where('apps', fn ($apps) => collect($apps)->contains(fn ($app) => $app['id'] === $this->demoApp->id
                && collect($app['versions'])->contains(fn ($row) => $row['id'] === $version->id
                    && $row['version'] === '33.0.1'
                    && $row['chart_version'] === '9.0.0'
                    && $row['updates']['image'] === '33.0.2'
                    && $row['updates']['chart'] === '9.0.1')))
        );
});

it('shows apps with updates available on the admin dashboard', function () {
    $version = checkedVersion();
    checkedVersion(['name' => '1.0.0', 'status' => 'deactivated']);

    $this->withoutVite()
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Dashboard/DashboardOverview')
            ->where('app_updates', fn ($updates) => collect($updates)->pluck('id')->all() === [$version->id]
                && collect($updates)->first()['image'] === '33.0.2'
                && collect($updates)->first()['chart'] === '9.0.1')
        );
});
