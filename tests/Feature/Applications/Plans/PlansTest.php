<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Organization;
use App\Support\Facades\Settings;
use App\User;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->demoApp = Application::where('slug', 'demo_app')->first();

    $this->appPlan = AppPlan::factory()->create([
        'name' => 'Original App Plan',
        'description' => 'Original description',
        'application_id' => $this->demoApp->id,
        'archive' => false,
        'settings' => [
            'server_type' => 'separate',
            'base' => ['max' => 0, 'price' => 5, 'storage' => 10, 'price_id' => 'prod_base'],
            'basic' => ['max' => 2, 'name' => 'Basic', 'price' => 2, 'amount' => 1, 'storage' => 1, 'price_id' => 'prod_basic'],
            'storage' => ['max' => 50, 'price' => 1, 'amount' => 5, 'price_id' => 'prod_sto'],
            'standard' => ['max' => 5, 'price' => 3, 'storage' => 2, 'price_id' => 'prod_std'],
        ],
    ]);
});

function appPlanUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Updated App Plan',
        'description' => 'Updated description',
        'default' => false,
        'payment_enabled' => false,
        'server_type' => 'separate',
        'self_registration_enabled' => false,
        'domain_enabled' => false,
        'domain_max' => 0,
    ], $overrides);
}

it('renders the app plan edit page for admin', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertStatus(200);
});

it('defaults enabled_currencies to USD when no control panel setting exists', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertInertia(fn ($page) => $page->where('enabled_currencies', ['USD']));
});

it('updates the app plan name and description', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'name' => 'Renamed App Plan',
            'description' => 'Renamed description',
        ])
    )->assertRedirect();

    $this->assertDatabaseHas('app_plans', [
        'id' => $this->appPlan->id,
        'name' => 'Renamed App Plan',
        'description' => 'Renamed description',
    ]);
});

it('updates base, standard, basic, and storage prices for a single enabled currency', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'prices' => [
                'base' => ['USD' => ['amount' => 12, 'price_id' => 'usd_base']],
                'standard' => ['USD' => ['amount' => 8, 'price_id' => 'usd_std']],
                'basic' => ['USD' => ['amount' => 4, 'price_id' => 'usd_basic']],
                'storage' => ['USD' => ['amount' => 2, 'price_id' => 'usd_sto']],
            ],
        ])
    )->assertRedirect();

    $this->appPlan->refresh();

    expect($this->appPlan->setting('base.prices.USD.amount'))->toEqual(12);
    expect($this->appPlan->setting('base.prices.USD.price_id'))->toBe('usd_base');
    expect($this->appPlan->setting('standard.prices.USD.amount'))->toEqual(8);
    expect($this->appPlan->setting('basic.prices.USD.amount'))->toEqual(4);
    expect($this->appPlan->setting('storage.prices.USD.amount'))->toEqual(2);
});

// ---------------------------------------------------------------------------
// Multi-currency tests
// ---------------------------------------------------------------------------

it('saves distinct prices per enabled currency for the app plan', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'prices' => [
                'base' => [
                    'USD' => ['amount' => 10, 'price_id' => 'usd_base'],
                    'CAD' => ['amount' => 14, 'price_id' => 'cad_base'],
                ],
                'standard' => [
                    'USD' => ['amount' => 5, 'price_id' => 'usd_std'],
                    'CAD' => ['amount' => 7, 'price_id' => 'cad_std'],
                ],
                'basic' => [
                    'USD' => ['amount' => 3, 'price_id' => 'usd_basic'],
                    'CAD' => ['amount' => 4, 'price_id' => 'cad_basic'],
                ],
                'storage' => [
                    'USD' => ['amount' => 1, 'price_id' => 'usd_sto'],
                    'CAD' => ['amount' => 2, 'price_id' => 'cad_sto'],
                ],
            ],
        ])
    )->assertRedirect();

    $this->appPlan->refresh();

    expect($this->appPlan->setting('base.prices.USD.amount'))->toEqual(10);
    expect($this->appPlan->setting('base.prices.CAD.amount'))->toEqual(14);
    expect($this->appPlan->setting('base.prices.USD.price_id'))->toBe('usd_base');
    expect($this->appPlan->setting('base.prices.CAD.price_id'))->toBe('cad_base');

    expect($this->appPlan->setting('standard.prices.USD.amount'))->toEqual(5);
    expect($this->appPlan->setting('standard.prices.CAD.amount'))->toEqual(7);

    expect($this->appPlan->setting('basic.prices.USD.amount'))->toEqual(3);
    expect($this->appPlan->setting('basic.prices.CAD.amount'))->toEqual(4);

    expect($this->appPlan->setting('storage.prices.USD.amount'))->toEqual(1);
    expect($this->appPlan->setting('storage.prices.CAD.amount'))->toEqual(2);
});

it('gives the edit page pricing components and a feature list, even for a plan that lacks them', function () {
    $this->appPlan->settings = ['server_type' => 'separate'];
    $this->appPlan->features = null;
    $this->appPlan->save();

    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertInertia(fn ($page) => $page
        ->has('plan.settings.base')
        ->has('plan.settings.standard')
        ->has('plan.settings.basic')
        ->has('plan.settings.storage')
        ->where('plan.features', [])
    );
});

it('passes all enabled currencies to the app plan edit page', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD', 'EUR']));

    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertInertia(fn ($page) => $page->where('enabled_currencies', ['USD', 'CAD', 'EUR']));
});

// ---------------------------------------------------------------------------
// Breadcrumb tests
// ---------------------------------------------------------------------------

it('breadcrumbs a normal plan through the apps plan list', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}"
    );

    $response->assertInertia(fn ($page) => $page
        ->where('breadcrumbs.1.label', $this->demoApp->name)
        ->where('breadcrumbs.2.label', __('admin.applications.plans.plans'))
        ->where('breadcrumbs.3.label', $this->appPlan->name)
    );
});

it('breadcrumbs a hidden shared-app plan back to the shared app', function () {
    $hiddenPlan = AppPlan::factory()->create([
        'name' => 'My Shared CRM',
        'application_id' => $this->demoApp->id,
        'hidden' => true,
    ]);

    $sharedAppInstance = new AppInstance;
    $sharedAppInstance->application_id = $this->demoApp->id;
    $sharedAppInstance->organization_id = $this->user->organization_id;
    $sharedAppInstance->version_id = 1;
    $sharedAppInstance->plan_id = $hiddenPlan->id;
    $sharedAppInstance->name = 'shared-crm';
    $sharedAppInstance->label = 'My Shared CRM';
    $sharedAppInstance->status = 'active';
    $sharedAppInstance->save();

    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$hiddenPlan->id}"
    );

    $response->assertInertia(fn ($page) => $page
        ->where('breadcrumbs.0.label', __('admin.shared_apps.shared_apps'))
        ->where('breadcrumbs.0.url', '/admin/service/shared-apps')
        ->where('breadcrumbs.1.label', $sharedAppInstance->label)
        ->where('breadcrumbs.1.url', '/admin/service/shared-apps/'.$sharedAppInstance->id)
        ->where('breadcrumbs.2.label', __('admin.shared_apps.plan_settings'))
    );
});

// ---------------------------------------------------------------------------
// Hidden (shared-app) plan update tests
// ---------------------------------------------------------------------------

it('does not require server_type when updating a hidden shared-app plan', function () {
    $hiddenPlan = AppPlan::factory()->create([
        'name' => 'My Shared CRM',
        'application_id' => $this->demoApp->id,
        'hidden' => true,
    ]);

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$hiddenPlan->id}",
        appPlanUpdatePayload(['name' => 'Renamed Shared CRM', 'server_type' => null])
    )->assertSessionDoesntHaveErrors('server_type');

    $this->assertDatabaseHas('app_plans', [
        'id' => $hiddenPlan->id,
        'name' => 'Renamed Shared CRM',
    ]);
});

it('keeps the plan\'s server type when an update does not send one', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload(['server_type' => null])
    )->assertSessionDoesntHaveErrors('server_type');

    expect($this->appPlan->fresh()->setting('server_type'))->toBe('separate');
});

// ---------------------------------------------------------------------------
// Creating a plan asks for its server type
// ---------------------------------------------------------------------------

function sharedDemoApp(Application $app): AppInstance
{
    $organization = Organization::factory()->create([
        'name' => 'Shared Apps',
        'slug' => 'shared',
        'type' => 'shared',
        'status' => 'active',
    ]);

    $plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'hidden' => true,
        'web_server_id' => 11,
        'database_server_id' => 12,
        'sso_server_id' => 13,
    ]);

    $instance = new AppInstance;
    $instance->application_id = $app->id;
    $instance->organization_id = $organization->id;
    $instance->version_id = 1;
    $instance->plan_id = $plan->id;
    $instance->name = 'shared-demo';
    $instance->label = 'Shared Demo';
    $instance->status = 'active';
    $instance->save();

    return $instance;
}

it('creates a plan that activates its own app by default', function () {
    $this->actingAs($this->user)->post("/admin/apps/{$this->demoApp->slug}/plans", [
        'name' => 'New plan',
        'description' => 'New description',
    ])->assertRedirect();

    $plan = AppPlan::where('name', 'New plan')->firstOrFail();

    expect($plan->setting('server_type'))->toBe('separate')
        ->and($plan->shared_app_id)->toBeNull();
});

it('creates a plan connected to a shared app, using its servers', function () {
    $shared = sharedDemoApp($this->demoApp);

    $this->actingAs($this->user)->post("/admin/apps/{$this->demoApp->slug}/plans", [
        'name' => 'Shared plan',
        'description' => 'Shared description',
        'server_type' => 'shared',
        'shared_app' => $shared->id,
    ])->assertRedirect();

    $plan = AppPlan::where('name', 'Shared plan')->firstOrFail();

    expect($plan->setting('server_type'))->toBe('shared')
        ->and($plan->shared_app_id)->toBe($shared->id)
        ->and($plan->web_server_id)->toBe(11)
        ->and($plan->database_server_id)->toBe(12)
        ->and($plan->sso_server_id)->toBe(13);
});

it('needs a shared app to create a shared plan', function () {
    $this->actingAs($this->user)->post("/admin/apps/{$this->demoApp->slug}/plans", [
        'name' => 'Shared plan',
        'description' => 'Shared description',
        'server_type' => 'shared',
    ])->assertSessionHasErrors('shared_app');

    expect(AppPlan::where('name', 'Shared plan')->exists())->toBeFalse();
});

it('rejects an unknown server type when creating a plan', function () {
    $this->actingAs($this->user)->post("/admin/apps/{$this->demoApp->slug}/plans", [
        'name' => 'Odd plan',
        'description' => 'Odd description',
        'server_type' => 'cluster',
    ])->assertSessionHasErrors('server_type');
});

it('does not let an app that can\'t be shared create a shared plan', function () {
    // WordPress's profile doesn't declare it as shareable
    $app = Application::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress']);
    $shared = sharedDemoApp($this->demoApp);

    $this->actingAs($this->user)->post("/admin/apps/{$app->slug}/plans", [
        'name' => 'Shared plan',
        'description' => 'Shared description',
        'server_type' => 'shared',
        'shared_app' => $shared->id,
    ])->assertSessionHasErrors('server_type');
});

it('only offers the shared apps of the same application', function () {
    $shared = sharedDemoApp($this->demoApp);

    $this->actingAs($this->user)->get("/admin/apps/{$this->demoApp->slug}/plans")
        ->assertInertia(fn ($page) => $page
            ->where('app.can.shareable', true)
            ->where('shared_apps.0.id', $shared->id)
            ->where('shared_apps.0.name', 'Shared Demo')
        );
});

// ---------------------------------------------------------------------------
// Tabs of a plan connected to a shared app
// ---------------------------------------------------------------------------

it('shows every tab of a plan that activates its own app', function () {
    $this->actingAs($this->user)
        ->get("/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('plan.tabs', ['features' => true, 'configurations' => true, 'secrets' => true])
        );
});

it('hides the features, configurations and secrets tabs of a plan connected to a shared app', function () {
    $this->appPlan->updateSettings(['server_type' => 'shared']);
    $this->appPlan->save();

    foreach (['', '/edit', '/features', '/configurations', '/secrets'] as $page) {
        $this->actingAs($this->user)
            ->get("/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}{$page}")
            ->assertInertia(fn ($inertia) => $inertia
                ->where('plan.tabs', ['features' => false, 'configurations' => false, 'secrets' => false])
            );
    }
});

it('keeps the features tab of a shared plan for a multisite app', function () {
    DemoAppProfile::$multisite = true;
    $this->appPlan->updateSettings(['server_type' => 'shared']);
    $this->appPlan->save();

    try {
        $this->actingAs($this->user)
            ->get("/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit")
            ->assertInertia(fn ($page) => $page
                ->where('plan.tabs', ['features' => true, 'configurations' => false, 'secrets' => false])
            );
    } finally {
        DemoAppProfile::$multisite = false;
    }
});

it('rejects a currency amount that is not numeric for the app plan', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'prices' => [
                'base' => [
                    'USD' => ['amount' => 10, 'price_id' => 'usd_base'],
                    'CAD' => ['amount' => 'not-a-number', 'price_id' => 'cad_base'],
                ],
            ],
        ])
    )->assertSessionHasErrors('prices.base.CAD.amount');
});

it('clones an app plan under a new name', function () {
    $this->appPlan->forceFill(['is_default' => true, 'archive' => true, 'display_order' => 1])->save();

    $response = $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/clone",
        ['name' => 'Cloned App Plan']
    );

    $clone = AppPlan::where('name', 'Cloned App Plan')->firstOrFail();
    $response->assertRedirect("/admin/apps/{$this->demoApp->slug}/plans/{$clone->id}");

    expect($clone->id)->not->toBe($this->appPlan->id)
        ->and($clone->application_id)->toBe($this->demoApp->id)
        ->and($clone->description)->toBe('Original description')
        ->and($clone->settings)->toEqual($this->appPlan->settings)
        ->and($clone->is_default)->toBeFalse()
        ->and($clone->archive)->toBeFalse()
        ->and($clone->display_order)->toBeGreaterThan(1);

    $this->appPlan->refresh();
    expect($this->appPlan->name)->toBe('Original App Plan')
        ->and($this->appPlan->is_default)->toBeTrue();
});

it('requires a name to clone an app plan', function () {
    $count = AppPlan::count();

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/clone",
        []
    )->assertSessionHasErrors('name');

    expect(AppPlan::count())->toBe($count);
});

it('does not clone a hidden shared app plan', function () {
    $this->appPlan->forceFill(['hidden' => true])->save();
    $count = AppPlan::count();

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/clone",
        ['name' => 'Cloned App Plan']
    )->assertNotFound();

    expect(AppPlan::count())->toBe($count);
});
