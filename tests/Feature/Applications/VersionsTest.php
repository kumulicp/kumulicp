<?php

use App\AppVersion;
use App\AppVersionImage;
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

it('creates a version with previous_version — copies its other images', function () {
    $source = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '1.0',
        'settings' => ['image_repo_name' => 'library/demo', 'image_registry' => 'docker.io'],
    ]);
    AppVersionImage::factory()->create(['app_version_id' => $source->id, 'key' => 'redis', 'repository' => 'library/redis', 'tag' => '8.0.1', 'position' => 1]);

    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '2.0',
        'copy_from' => 'previous_version',
        'copy_version' => $source->id,
    ])->assertRedirect("/admin/apps/{$this->demoApp->slug}/versions/2.0");

    $version = AppVersion::where('application_id', $this->demoApp->id)->where('name', '2.0')->firstOrFail();

    // The primary image is made from the settings it was given, the rest are copied
    expect($version->images->pluck('key')->all())->toBe(['primary', 'redis'])
        ->and($version->primaryImage()->effectiveTag())->toBe('2.0')
        ->and($version->images->last()->tag)->toBe('8.0.1');
});

// A version the update check found newer tags for the other images of
function updatableVersion(array $images): AppVersion
{
    $version = AppVersion::factory()->create([
        'application_id' => test()->demoApp->id,
        'name' => '33.0.1',
        'settings' => ['image_repo_name' => 'library/demo', 'image_registry' => 'docker.io', 'chart_version' => '9.0.0'],
        'update_check' => [
            'checked_at' => '2026-10-14T03:00:00+00:00',
            'image' => null,
            'images' => collect($images)->map(fn ($image) => ['current' => $image['tag'], 'latest' => $image['latest'], 'policy' => 'track', 'error' => null])->all(),
            'chart' => null,
        ],
    ]);

    foreach ($images as $key => $image) {
        AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => $key, 'tag' => $image['tag'], 'group' => $image['group'] ?? null]);
    }

    return $version->fresh();
}

it('creates a version from an update with only the picked images at their newer tag', function () {
    $source = updatableVersion([
        'imaginary' => ['tag' => '1.0.0', 'latest' => '1.1.0'],
        'redis' => ['tag' => '8.0.1', 'latest' => '8.0.2'],
    ]);

    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '34.0.0',
        'copy_from' => 'update',
        'copy_version' => $source->id,
        'images' => ['redis'],
    ])->assertSessionDoesntHaveErrors();

    $version = AppVersion::where('application_id', $this->demoApp->id)->where('name', '34.0.0')->firstOrFail();

    expect($version->images->pluck('tag', 'key')->only(['imaginary', 'redis'])->all())->toBe(['imaginary' => '1.0.0', 'redis' => '8.0.2'])
        ->and($version->primaryImage()->effectiveTag())->toBe('34.0.0');
});

it('moves a group of images together when one of them is picked', function () {
    $source = updatableVersion([
        'synapse' => ['tag' => '1.0.0', 'latest' => '1.1.0', 'group' => 'matrix'],
        'admin' => ['tag' => '2.0.0', 'latest' => '2.1.0', 'group' => 'matrix'],
    ]);

    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '34.0.0',
        'copy_from' => 'update',
        'copy_version' => $source->id,
        'images' => ['synapse'],
    ])->assertSessionDoesntHaveErrors();

    $version = AppVersion::where('application_id', $this->demoApp->id)->where('name', '34.0.0')->firstOrFail();

    expect($version->images->pluck('tag', 'key')->only(['synapse', 'admin'])->all())->toBe(['synapse' => '1.1.0', 'admin' => '2.1.0']);
});

it('does not move any image when none are picked, or when the version is not made from an update', function () {
    $source = updatableVersion(['imaginary' => ['tag' => '1.0.0', 'latest' => '1.1.0']]);

    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '34.0.0',
        'copy_from' => 'update',
        'copy_version' => $source->id,
    ])->assertSessionDoesntHaveErrors();

    $this->post("/admin/apps/{$this->demoApp->slug}/versions", [
        'version' => '35.0.0',
        'copy_from' => 'previous_version',
        'copy_version' => $source->id,
        'images' => ['imaginary'],
    ])->assertSessionDoesntHaveErrors();

    foreach (['34.0.0', '35.0.0'] as $name) {
        $version = AppVersion::where('application_id', $this->demoApp->id)->where('name', $name)->firstOrFail();

        expect($version->images->firstWhere('key', 'imaginary')->tag)->toBe('1.0.0');
    }
});

it('offers an update on the versions page when only another image has a newer tag', function () {
    $source = updatableVersion(['imaginary' => ['tag' => '1.0.0', 'latest' => '1.1.0', 'group' => 'media']]);

    $this->withoutVite()
        ->get("/admin/apps/{$this->demoApp->slug}/versions")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('versions', fn ($versions) => collect($versions)->contains(fn ($row) => $row['id'] === $source->id
                && $row['updates']['image'] === null
                && $row['updates']['images'][0]['key'] === 'imaginary'
                && $row['updates']['images'][0]['group'] === 'media'))
        );
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

it('shows newer tags for the other images, and the ones that could not be checked', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '33.0.1',
        'settings' => ['chart_version' => '9.0.0'],
        'update_check' => [
            'checked_at' => '2026-10-14T03:00:00+00:00',
            'image' => null,
            'images' => [
                'imaginary' => ['current' => '20261109_101109', 'latest' => '20270101_000000', 'policy' => 'track', 'error' => null],
                'redis' => ['current' => '8.0.1', 'latest' => null, 'policy' => 'track', 'error' => 'The registry timed out'],
                'proxy' => ['current' => '2.0.0', 'latest' => null, 'policy' => 'pinned', 'error' => null],
            ],
            'chart' => null,
        ],
    ]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'imaginary', 'label' => 'Imaginary', 'tag' => '20261109_101109', 'position' => 1]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'redis', 'tag' => '8.0.1', 'position' => 2]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'proxy', 'tag' => '2.0.0', 'position' => 3]);

    $this->withoutVite()
        ->get("/admin/apps/{$this->demoApp->slug}/versions")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Applications/Versions/VersionsList')
            ->where('versions', fn ($versions) => collect($versions)->contains(fn ($row) => $row['id'] === $version->id
                && $row['updates']['image'] === null
                && count($row['updates']['images']) === 1
                && $row['updates']['images'][0]['key'] === 'imaginary'
                && $row['updates']['images'][0]['label'] === 'Imaginary'
                && $row['updates']['images'][0]['current'] === '20261109_101109'
                && $row['updates']['images'][0]['latest'] === '20270101_000000'
                && count($row['updates']['images_errors']) === 1
                && $row['updates']['images_errors'][0]['label'] === 'redis'
                && $row['updates']['images_errors'][0]['error'] === 'The registry timed out'))
        );
});

it('lists a version on the dashboard when only one of its other images has a newer tag', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->demoApp->id,
        'name' => '33.0.1',
        'update_check' => [
            'checked_at' => '2026-10-14T03:00:00+00:00',
            'image' => null,
            'images' => ['imaginary' => ['current' => '1.0.0', 'latest' => '1.1.0', 'policy' => 'track', 'error' => null]],
            'chart' => null,
        ],
    ]);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'imaginary', 'tag' => '1.0.0']);

    $this->withoutVite()
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('app_updates', fn ($updates) => collect($updates)->contains(fn ($row) => $row['id'] === $version->id
                && $row['image'] === null
                && $row['images'][0]['latest'] === '1.1.0'))
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
