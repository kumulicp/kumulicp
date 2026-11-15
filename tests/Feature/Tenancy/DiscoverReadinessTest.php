<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TestSupports;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The seeded demo organization is a superaccount and the demo user is an admin.
// Three apps: one ready, one turned off (blocked) and one whose plan nobody has edited (warning).

beforeEach(function () {
    (new TestSupports)->seed();
    useSingleTenancy();

    $this->ready_app = discoverApp('ready-app');
    $this->off_app = discoverApp('off-app', ['enabled' => false]);
    $this->warn_app = discoverApp('warn-app', edited: false);
});

function discoverApp(string $slug, array $attributes = [], bool $edited = true): Application
{
    $app = Application::factory()->create(array_merge(['slug' => $slug, 'name' => ucfirst($slug), 'enabled' => true], $attributes));
    AppVersion::factory()->create(['application_id' => $app->id]);
    $plan = AppPlan::factory()->create(['application_id' => $app->id]);

    if ($edited) {
        test()->travel(1)->minute();
        $plan->update(['name' => 'Edited']);
        test()->travelBack();
    }

    return $app;
}

function discoverAdmin(): User
{
    return User::where('username', 'demo')->firstOrFail();
}

function discoverMakeNotAdmin(): void
{
    AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();
}

it('lists every app with what it still needs for an admin', function () {
    $this->actingAs(discoverAdmin())
        ->get('/discover')
        ->assertInertia(fn ($page) => $page
            ->where('can.manage', true)
            ->where('apps', function ($apps) {
                $apps = collect($apps)->keyBy('slug');

                return $apps->keys()->sort()->values()->all() === ['off-app', 'ready-app', 'warn-app']
                    && $apps['ready-app']['readiness']['status'] === 'ready'
                    && $apps['off-app']['readiness']['status'] === 'blocked'
                    && $apps['off-app']['readiness']['issues'] === 1
                    && $apps['warn-app']['readiness']['status'] === 'warning';
            }));
});

it('only lists the apps that can be installed for everyone else, without the details', function () {
    discoverMakeNotAdmin();

    $this->actingAs(discoverAdmin())
        ->get('/discover')
        ->assertInertia(fn ($page) => $page
            ->where('can.manage', false)
            ->where('apps', function ($apps) {
                $apps = collect($apps)->keyBy('slug');

                return $apps->keys()->sort()->values()->all() === ['ready-app', 'warn-app']
                    && $apps['ready-app']['readiness'] === null
                    && $apps['warn-app']['readiness'] === null;
            }));
});

it('shows an admin what is wrong with an app and where to fix it', function () {
    $this->actingAs(discoverAdmin())
        ->get('/discover/off-app')
        ->assertInertia(fn ($page) => $page
            ->where('readiness.status', 'blocked')
            ->where('readiness.items.0.key', 'enabled')
            ->where('readiness.items.0.actions.0.url', '/admin/apps/off-app/edit')
            ->where('can.activate', false)
            ->where('authorization.deny.code', 'app_not_ready'));
});

it('lets an admin install an app that is ready, with a warning about its plan', function () {
    $this->actingAs(discoverAdmin())
        ->get('/discover/warn-app')
        ->assertInertia(fn ($page) => $page
            ->where('readiness.status', 'warning')
            ->where('readiness.items.0.key', 'plan_configured')
            ->where('can.activate', true));

    $this->get('/discover/ready-app')
        ->assertInertia(fn ($page) => $page->where('readiness.items', [])->where('can.activate', true));
});

it('hides an app that is not ready from everyone but an admin', function () {
    discoverMakeNotAdmin();
    $this->actingAs(discoverAdmin());

    $this->get('/discover/off-app')->assertNotFound();
    $this->get('/discover/ready-app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('readiness', null));
});

it('only lets an admin install an app', function () {
    discoverMakeNotAdmin();
    $this->actingAs(discoverAdmin());

    expect(Gate::inspect('activate-app', $this->ready_app)->code())->toBe('admin_required');

    $plan = $this->ready_app->plans()->first();
    $this->post("/discover/ready-app/plans/{$plan->id}/activate", ['label' => 'x', 'domain' => 'default'])->assertForbidden();
});

it('refuses to install an app that is not ready even with a hand-made request', function () {
    $this->actingAs(discoverAdmin());

    expect(Gate::inspect('activate-app', $this->off_app)->code())->toBe('app_not_ready');

    $plan = $this->off_app->plans()->first();
    $this->post("/discover/off-app/plans/{$plan->id}/activate", ['label' => 'x', 'domain' => 'default'])->assertForbidden();
    $this->get("/discover/off-app/plans/{$plan->id}/review")->assertForbidden();
});

it('only lets an admin deactivate or bring back an app', function () {
    $instance = AppInstance::factory()->create([
        'organization_id' => 1,
        'application_id' => $this->ready_app->id,
        'plan_id' => $this->ready_app->plans()->first()->id,
        'label' => 'Ready app',
    ]);

    $this->actingAs(discoverAdmin());
    expect(Gate::allows('deactivate-app', $instance))->toBeTrue();

    discoverMakeNotAdmin();
    expect(Gate::allows('deactivate-app', $instance))->toBeFalse();
    expect(Gate::allows('reactivate-app', $instance))->toBeFalse();
});

it('creates a default plan in one click for an app that has none', function () {
    $app = Application::factory()->create(['slug' => 'bare-app', 'enabled' => true]);
    $this->actingAs(discoverAdmin());

    $response = $this->post('/admin/apps/bare-app/plans/default');

    $plan = $app->plans()->sole();
    $response->assertRedirect("/admin/apps/bare-app/plans/{$plan->id}/edit");
    expect($plan->name)->toBe(__('admin.applications.plans.first_plan_name'));
});

it('does not create a second plan from the one-click button', function () {
    $this->actingAs(discoverAdmin());

    $this->post('/admin/apps/ready-app/plans/default')->assertRedirect('/admin/apps/ready-app/plans');

    expect($this->ready_app->plans()->count())->toBe(1);
});

it('shows the same checklist on the admin app page', function () {
    $this->actingAs(discoverAdmin())
        ->get('/admin/apps/off-app')
        ->assertInertia(fn ($page) => $page->where('readiness.status', 'blocked'));
});

it('lists apps the multi-tenant way and shows no checklist in multi-tenant mode', function () {
    useMultiTenancy();
    $this->actingAs(discoverAdmin());

    $this->get('/discover')->assertInertia(fn ($page) => $page->has('apps', 0));
    $this->get('/admin/apps/off-app')->assertInertia(fn ($page) => $page->where('readiness', null));
});
