<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Organization;
use App\Support\Facades\Settings;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    expect(storedPrice($this->appPlan, 'base', 'USD'))->toEqual(12);
    expect(storedPrice($this->appPlan, 'base', 'USD', 'price_id'))->toBe('usd_base');
    expect(storedPrice($this->appPlan, 'standard', 'USD'))->toEqual(8);
    expect(storedPrice($this->appPlan, 'basic', 'USD'))->toEqual(4);
    expect(storedPrice($this->appPlan, 'storage', 'USD'))->toEqual(2);
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

    expect(storedPrice($this->appPlan, 'base', 'USD'))->toEqual(10);
    expect(storedPrice($this->appPlan, 'base', 'CAD'))->toEqual(14);
    expect(storedPrice($this->appPlan, 'base', 'USD', 'price_id'))->toBe('usd_base');
    expect(storedPrice($this->appPlan, 'base', 'CAD', 'price_id'))->toBe('cad_base');

    expect(storedPrice($this->appPlan, 'standard', 'USD'))->toEqual(5);
    expect(storedPrice($this->appPlan, 'standard', 'CAD'))->toEqual(7);

    expect(storedPrice($this->appPlan, 'basic', 'USD'))->toEqual(3);
    expect(storedPrice($this->appPlan, 'basic', 'CAD'))->toEqual(4);

    expect(storedPrice($this->appPlan, 'storage', 'USD'))->toEqual(1);
    expect(storedPrice($this->appPlan, 'storage', 'CAD'))->toEqual(2);
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

it('saves where the app can be reached and which app it is an addon to on the plan', function () {
    $parent = Application::factory()->create();

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'can_update_domain' => true,
            'domain_option' => ['base', 'subdomains'],
            'parent_app' => $parent->id,
        ])
    )->assertSessionHasNoErrors();

    $plan = $this->appPlan->fresh();

    expect($plan->canUpdateDomain())->toBeTrue()
        ->and($plan->domainOptions())->toBe(['base', 'subdomains'])
        ->and($plan->hasDomainOption('base'))->toBeTrue()
        ->and($plan->hasDomainOption('parent'))->toBeFalse()
        ->and($plan->parentApplication()?->id)->toBe($parent->id);
});

it('clears the parent app of a plan', function () {
    $parent = Application::factory()->create();
    $this->appPlan->updateSetting('parent_app_id', $parent->id);

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload(['domain_option' => [], 'parent_app' => null])
    )->assertSessionHasNoErrors();

    expect($this->appPlan->fresh()->parentApplication())->toBeNull();
});

it('leaves the domain settings of a plan alone when an update does not send them', function () {
    $parent = Application::factory()->create();
    $this->appPlan->updateSettings(['can_update_domain' => true, 'domain_option' => ['base'], 'parent_app_id' => $parent->id]);
    $this->appPlan->save();

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload()
    )->assertSessionHasNoErrors();

    $plan = $this->appPlan->fresh();

    expect($plan->canUpdateDomain())->toBeTrue()
        ->and($plan->domainOptions())->toBe(['base'])
        ->and($plan->parentApplication()?->id)->toBe($parent->id);
});

it('rejects an unknown domain option, a missing parent app and an app that is its own parent on a plan', function () {
    $url = "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}";

    $this->actingAs($this->user)->post($url, appPlanUpdatePayload(['domain_option' => ['everywhere']]))
        ->assertSessionHasErrors('domain_option.0');
    $this->actingAs($this->user)->post($url, appPlanUpdatePayload(['parent_app' => 999999]))
        ->assertSessionHasErrors('parent_app');
    $this->actingAs($this->user)->post($url, appPlanUpdatePayload(['parent_app' => $this->demoApp->id]))
        ->assertSessionHasErrors('parent_app');
});

it('gives the plan edit page its domain settings and the other apps to pick a parent from', function () {
    $other = Application::factory()->create(['name' => 'Another App']);
    $this->appPlan->updateSettings(['can_update_domain' => true, 'domain_option' => ['primary'], 'parent_app_id' => $other->id]);
    $this->appPlan->save();

    $this->actingAs($this->user)->get("/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('plan.can_update_domain', true)
            ->where('plan.domain_option', ['primary'])
            ->where('plan.parent_app', $other->id)
            ->where('apps', fn ($apps) => collect($apps)->pluck('value')->contains($other->id)
                && ! collect($apps)->pluck('value')->contains($this->demoApp->id))
        );
});

it('starts a new plan with the domain settings the app already has', function () {
    $parent = Application::factory()->create();
    $this->appPlan->updateSettings(['can_update_domain' => true, 'domain_option' => ['base'], 'parent_app_id' => $parent->id]);
    $this->appPlan->save();

    $this->actingAs($this->user)->post("/admin/apps/{$this->demoApp->slug}/plans", [
        'name' => 'Another plan',
        'description' => 'Another description',
    ])->assertRedirect();

    $plan = AppPlan::where('name', 'Another plan')->firstOrFail();

    expect($plan->canUpdateDomain())->toBeTrue()
        ->and($plan->domainOptions())->toBe(['base'])
        ->and($plan->parentApplication()?->id)->toBe($parent->id);
});

it('takes the domain settings an app had onto each of its plans, and drops them from the app', function () {
    $migration = require database_path('migrations/2026_11_17_100000_move_domain_and_parent_app_settings_to_app_plans.php');
    $migration->down();

    $parent = Application::factory()->create();
    DB::table('applications')->where('id', $this->demoApp->id)->update([
        'can_update_domain' => true,
        'domain_option' => json_encode(['base', 'parent']),
        'parent_app_id' => $parent->id,
    ]);

    $migration->up();

    foreach ($this->demoApp->plans as $plan) {
        expect($plan->canUpdateDomain())->toBeTrue()
            ->and($plan->domainOptions())->toBe(['base', 'parent'])
            ->and($plan->parentApplication()?->id)->toBe($parent->id)
            ->and($plan->setting('base'))->toBeArray();
    }

    foreach (['access_type', 'can_update_domain', 'domain_option', 'parent_app_id'] as $column) {
        expect(Schema::hasColumn('applications', $column))->toBeFalse();
    }
});

function featuresUrl(): string
{
    return '/admin/apps/'.test()->demoApp->slug.'/plans/'.test()->appPlan->id.'/features';
}

it('shows the features page the enabled currencies and the price of each feature in them', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));
    $this->appPlan->updateSetting('features', ['optional-feature' => ['status' => 'optional', 'price' => 2, 'price_id' => 'usd_feat']]);
    $this->appPlan->setPrice('feature.optional-feature', 'CAD', 3, 'cad_feat');

    $this->actingAs($this->user)->get(featuresUrl())->assertInertia(fn ($page) => $page
        ->where('enabled_currencies', ['USD', 'CAD'])
        ->where('plan.settings.features.optional-feature.price_id', 'usd_feat')
        ->where('plan.settings.features.optional-feature.prices.CAD.price_id', 'cad_feat'));
});

it('saves the price of a feature in each enabled currency', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

    $this->actingAs($this->user)->put(featuresUrl(), ['features' => [
        'optional-feature' => ['status' => 'optional', 'prices' => [
            'USD' => ['amount' => 2, 'price_id' => 'usd_feat'],
            'CAD' => ['amount' => 3, 'price_id' => 'cad_feat'],
        ]],
    ]])->assertRedirect();

    $plan = $this->appPlan->fresh();
    expect(storedPrice($plan, 'feature.optional-feature', 'USD'))->toEqual(2);
    expect(storedPrice($plan, 'feature.optional-feature', 'USD', 'price_id'))->toBe('usd_feat');
    expect(storedPrice($plan, 'feature.optional-feature', 'CAD'))->toEqual(3);
    expect(storedPrice($plan, 'feature.optional-feature', 'CAD', 'price_id'))->toBe('cad_feat');
    // Billing reads the price in the organization's currency
    expect($plan->featurePriceId('optional-feature', 'CAD'))->toBe('cad_feat');
    // The flat price older code reads is the default currency's
    expect($plan->setting('features.optional-feature.price_id'))->toBe('usd_feat');
});

it('clears a currency of a feature price that was emptied', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));
    $this->appPlan->setPrice('feature.optional-feature', 'CAD', 3, 'cad_feat');

    $this->actingAs($this->user)->put(featuresUrl(), ['features' => [
        'optional-feature' => ['status' => 'optional', 'prices' => [
            'USD' => ['amount' => 2, 'price_id' => 'usd_feat'],
            'CAD' => ['amount' => '', 'price_id' => ''],
        ]],
    ]])->assertRedirect();

    expect(storedPrice($this->appPlan->fresh(), 'feature.optional-feature', 'CAD', 'price_id'))->toBeNull();
    expect(storedPrice($this->appPlan->fresh(), 'feature.optional-feature', 'USD', 'price_id'))->toBe('usd_feat');
});

it('does not save a feature price in a currency that is not enabled', function () {
    $this->actingAs($this->user)->put(featuresUrl(), ['features' => [
        'optional-feature' => ['status' => 'optional', 'prices' => [
            'USD' => ['amount' => 2, 'price_id' => 'usd_feat'],
            'EUR' => ['amount' => 5, 'price_id' => 'eur_feat'],
        ]],
    ]])->assertRedirect();

    expect(storedPrice($this->appPlan->fresh(), 'feature.optional-feature', 'USD', 'price_id'))->toBe('usd_feat');
    expect($this->appPlan->prices()->where('currency', 'EUR')->count())->toBe(0);
});

it('rejects a feature price that is not a number', function () {
    $this->actingAs($this->user)->put(featuresUrl(), ['features' => [
        'optional-feature' => ['status' => 'optional', 'prices' => ['USD' => ['amount' => 'not-a-number', 'price_id' => 'usd_feat']]],
    ]])->assertSessionHasErrors('features.optional-feature.prices.USD.amount');

    expect($this->appPlan->prices()->count())->toBe(0);
});

it('still saves the single price older forms send', function () {
    $this->actingAs($this->user)->put(featuresUrl(), ['features' => [
        'optional-feature' => ['status' => 'optional', 'price' => 4, 'price_id' => 'flat_feat'],
    ]])->assertRedirect();

    $plan = $this->appPlan->fresh();
    expect($plan->featurePriceId('optional-feature', 'USD'))->toBe('flat_feat');
    expect($plan->featurePriceAmount('optional-feature', 'USD'))->toEqual(4);
});
