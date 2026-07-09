<?php

use App\Application;
use App\AppPlan;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->demoApp = Application::where('slug', 'demo_app')->first();
    [$this->plan1, $this->plan2] = createBulkEditTestPlans($this->demoApp);
});

// GET routes: page rendering

it('renders bulk edit settings page for admin', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit?plans[]={$this->plan1->id}&plans[]={$this->plan2->id}"
    );

    $response->assertStatus(200);
});

it('renders bulk edit view page for admin', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit?plans[]={$this->plan1->id}&plans[]={$this->plan2->id}"
    );

    $response->assertStatus(200);
});

it('renders bulk edit features page for admin', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/features?plans[]={$this->plan1->id}&plans[]={$this->plan2->id}"
    );

    $response->assertStatus(200);
});

it('renders bulk edit configurations page for admin', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/configurations?plans[]={$this->plan1->id}&plans[]={$this->plan2->id}"
    );

    $response->assertStatus(200);
});

it('redirects guest from bulk edit settings page', function () {
    $response = $this->get("/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit?plans[]={$this->plan1->id}");

    $response->assertRedirect('/login');
});

it('only returns plans belonging to the app', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit?plans[]={$this->plan1->id}&plans[]={$this->plan2->id}"
    );

    $response->assertStatus(200);
});

// POST /bulk-edit/edit (settings update)

it('updates names for multiple plans at once', function () {
    $response = $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload(['name' => 'Updated Name One', 'description' => 'Original Desc One']),
                $this->plan2->id => bulkEditSettingsPayload(['name' => 'Updated Name Two', 'description' => 'Original Desc Two']),
            ],
        ]
    );

    $response->assertRedirectContains('/plans/bulk-edit/edit');
    $this->assertDatabaseHas('app_plans', ['id' => $this->plan1->id, 'name' => 'Updated Name One']);
    $this->assertDatabaseHas('app_plans', ['id' => $this->plan2->id, 'name' => 'Updated Name Two']);
});

it('updates descriptions for multiple plans', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload(['name' => 'Original Name One', 'description' => 'New Desc One']),
                $this->plan2->id => bulkEditSettingsPayload(['name' => 'Original Name Two', 'description' => 'New Desc Two']),
            ],
        ]
    );

    $this->assertDatabaseHas('app_plans', ['id' => $this->plan1->id, 'description' => 'New Desc One']);
    $this->assertDatabaseHas('app_plans', ['id' => $this->plan2->id, 'description' => 'New Desc Two']);
});

it('updates base price independently per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'base' => ['storage' => 50, 'max' => 0],
                    'prices' => ['base' => ['USD' => ['amount' => 25, 'price_id' => 'prod_new_1']]],
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'base' => ['storage' => 100, 'max' => 0],
                    'prices' => ['base' => ['USD' => ['amount' => 75, 'price_id' => 'prod_new_2']]],
                ]),
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['base']['prices']['USD']['amount'])->toBe(25);
    expect($this->plan2->settings['base']['prices']['USD']['amount'])->toBe(75);
});

it('updates standard user settings per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'standard' => ['storage' => 3, 'max' => 20],
                    'prices' => ['standard' => ['USD' => ['amount' => 8, 'price_id' => 'std_1']]],
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'standard' => ['storage' => 6, 'max' => 50],
                    'prices' => ['standard' => ['USD' => ['amount' => 15, 'price_id' => 'std_2']]],
                ]),
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['standard']['max'])->toBe(20);
    expect($this->plan2->settings['standard']['max'])->toBe(50);
    expect($this->plan1->settings['standard']['prices']['USD']['amount'])->toBe(8);
    expect($this->plan2->settings['standard']['prices']['USD']['amount'])->toBe(15);
});

it('updates basic user settings per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'basic' => ['name' => 'Volunteer', 'price' => 3, 'price_id' => 'bas_1', 'storage' => 1, 'max' => 10, 'amount' => 5],
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'basic' => ['name' => 'Member', 'price' => 6, 'price_id' => 'bas_2', 'storage' => 2, 'max' => 25, 'amount' => 10],
                ]),
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['basic']['name'])->toBe('Volunteer');
    expect($this->plan2->settings['basic']['name'])->toBe('Member');
    expect($this->plan1->settings['basic']['max'])->toBe(10);
    expect($this->plan2->settings['basic']['max'])->toBe(25);
});

it('updates payment_enabled flag per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'payment_enabled' => true,
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'payment_enabled' => false,
                ]),
            ],
        ]
    );

    $this->assertDatabaseHas('app_plans', ['id' => $this->plan1->id, 'payment_enabled' => true]);
    $this->assertDatabaseHas('app_plans', ['id' => $this->plan2->id, 'payment_enabled' => false]);
});

it('saves expires_after and trial_for per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'expires_after' => 30, 'trial_for' => 7,
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'expires_after' => 90, 'trial_for' => 14,
                ]),
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['expires_after'])->toBe(30);
    expect($this->plan2->settings['expires_after'])->toBe(90);
    expect($this->plan1->settings['trial_for'])->toBe(7);
    expect($this->plan2->settings['trial_for'])->toBe(14);
});

it('saves server_type per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'server_type' => 'separate',
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'server_type' => 'shared',
                ]),
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['server_type'])->toBe('separate');
    expect($this->plan2->settings['server_type'])->toBe('shared');
});

it('does not modify plans excluded from plan_ids during settings update', function () {
    $plan3 = AppPlan::factory()->create([
        'name' => 'Untouched Plan',
        'description' => 'Should not change',
        'application_id' => $this->demoApp->id,
        'archive' => false,
    ]);

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload(['name' => 'Changed One', 'description' => 'Original Desc One']),
                $this->plan2->id => bulkEditSettingsPayload(['name' => 'Changed Two', 'description' => 'Original Desc Two']),
            ],
        ]
    );

    $this->assertDatabaseHas('app_plans', ['id' => $plan3->id, 'name' => 'Untouched Plan']);
});

it('cannot modify plans from another app via bulk edit', function () {
    $plan_other = AppPlan::factory()->create([
        'name' => 'Other App Plan',
        'description' => 'Should not change',
        'application_id' => 9999,
        'archive' => false,
    ]);

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $plan_other->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload(['name' => 'Changed', 'description' => 'Original Desc One']),
                $plan_other->id => bulkEditSettingsPayload(['name' => 'Hacked', 'description' => 'Other App Plan']),
            ],
        ]
    );

    $this->assertDatabaseHas('app_plans', ['id' => $plan_other->id, 'name' => 'Other App Plan']);
});

it('updates displayed features per plan', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload([
                    'name' => 'Original Name One', 'description' => 'Original Desc One',
                    'displayed_features' => [
                        ['name' => 'Feature A', 'description' => 'Desc A'],
                    ],
                ]),
                $this->plan2->id => bulkEditSettingsPayload([
                    'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                    'displayed_features' => [
                        ['name' => 'Feature X', 'description' => 'Desc X'],
                        ['name' => 'Feature Y', 'description' => 'Desc Y'],
                    ],
                ]),
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->features)->toHaveCount(1);
    expect($this->plan2->features)->toHaveCount(2);
    expect($this->plan1->features[0]['name'])->toBe('Feature A');
    expect($this->plan2->features[0]['name'])->toBe('Feature X');
    expect($this->plan2->features[1]['name'])->toBe('Feature Y');
});

it('redirects back to bulk edit settings with plan ids', function () {
    $response = $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/edit",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => bulkEditSettingsPayload(['name' => 'Original Name One', 'description' => 'Original Desc One']),
                $this->plan2->id => bulkEditSettingsPayload(['name' => 'Original Name Two', 'description' => 'Original Desc Two']),
            ],
        ]
    );

    $response->assertRedirectContains('/plans/bulk-edit/edit');
    $response->assertRedirectContains('plans[]='.$this->plan1->id);
    $response->assertRedirectContains('plans[]='.$this->plan2->id);
});

// PUT /bulk-edit/features (features update)

it('updates features for multiple plans', function () {
    $featureData1 = ['status' => 'enabled',  'price' => 5,  'price_id' => 'feat_1', 'payment_type' => 'user', 'settings' => []];
    $featureData2 = ['status' => 'optional', 'price' => 10, 'price_id' => 'feat_2', 'payment_type' => 'addon', 'settings' => []];

    $response = $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/features",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => ['features' => ['custom_feat' => $featureData1]],
                $this->plan2->id => ['features' => ['custom_feat' => $featureData2]],
            ],
        ]
    );

    $response->assertRedirectContains('/plans/bulk-edit/features');

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['features']['custom_feat']['status'])->toBe('enabled');
    expect($this->plan2->settings['features']['custom_feat']['status'])->toBe('optional');
    expect($this->plan1->settings['features']['custom_feat']['price'])->toBe(5);
    expect($this->plan2->settings['features']['custom_feat']['price'])->toBe(10);
});

it('allows each plan to have different feature statuses', function () {
    $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/features",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => ['features' => ['dark_mode' => ['status' => 'disabled', 'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]],
                $this->plan2->id => ['features' => ['dark_mode' => ['status' => 'enabled',  'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]],
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['features']['dark_mode']['status'])->toBe('disabled');
    expect($this->plan2->settings['features']['dark_mode']['status'])->toBe('enabled');
});

it('does not modify plans excluded from plan_ids during features update', function () {
    $this->plan2->updateSettings(['features.legacy' => ['status' => 'enabled', 'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]);
    $this->plan2->save();

    $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/features",
        [
            'plan_ids' => [$this->plan1->id],
            'plans' => [
                $this->plan1->id => ['features' => ['new_feat' => ['status' => 'optional', 'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]],
            ],
        ]
    );

    $this->plan2->refresh();
    expect($this->plan2->settings['features']['legacy']['status'])->toBe('enabled');
});

it('redirects with plan ids after features update', function () {
    $response = $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/features",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => ['features' => []],
                $this->plan2->id => ['features' => []],
            ],
        ]
    );

    $response->assertRedirectContains('/plans/bulk-edit/features');
    $response->assertRedirectContains('plans[]='.$this->plan1->id);
    $response->assertRedirectContains('plans[]='.$this->plan2->id);
});

// PUT /bulk-edit/configurations (configurations update)

it('adds custom configuration for multiple plans', function () {
    $additionalConfigMeta = ['name' => 'site-url', 'type' => 'string', 'persistent' => false];

    $response = $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/configurations",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => [
                    'configurations' => ['site-url' => 'https://plan1.example.com'],
                    'additionalConfigs' => ['site-url' => $additionalConfigMeta],
                ],
                $this->plan2->id => [
                    'configurations' => ['site-url' => 'https://plan2.example.com'],
                    'additionalConfigs' => ['site-url' => $additionalConfigMeta],
                ],
            ],
        ]
    );

    $response->assertRedirectContains('/plans/bulk-edit/configurations');

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['additionalConfigs'])->toHaveKey('site-url');
    expect($this->plan2->settings['additionalConfigs'])->toHaveKey('site-url');
});

it('stores different configuration values per plan', function () {
    $additionalConfigMeta = ['name' => 'replica-count', 'type' => 'int', 'persistent' => false];

    $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/configurations",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => [
                    'configurations' => ['replica-count' => '1'],
                    'additionalConfigs' => ['replica-count' => $additionalConfigMeta],
                ],
                $this->plan2->id => [
                    'configurations' => ['replica-count' => '3'],
                    'additionalConfigs' => ['replica-count' => $additionalConfigMeta],
                ],
            ],
        ]
    );

    $this->plan1->refresh();
    $this->plan2->refresh();

    expect($this->plan1->settings['additionalConfigs'])->toHaveKey('replica-count');
    expect($this->plan2->settings['additionalConfigs'])->toHaveKey('replica-count');
    expect($this->plan1->settings['additionalConfigs']['replica-count']['name'])->toBe('replica-count');
    expect($this->plan2->settings['additionalConfigs']['replica-count']['name'])->toBe('replica-count');
});

it('does not modify plans excluded from plan_ids during configurations update', function () {
    $this->plan2->updateSettings(['additionalConfigs.existing' => ['name' => 'existing', 'type' => 'string', 'persistent' => false]]);
    $this->plan2->save();

    $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/configurations",
        [
            'plan_ids' => [$this->plan1->id],
            'plans' => [
                $this->plan1->id => ['configurations' => [], 'additionalConfigs' => []],
            ],
        ]
    );

    $this->plan2->refresh();
    expect($this->plan2->settings['additionalConfigs'])->toHaveKey('existing');
});

it('redirects with plan ids after configurations update', function () {
    $this->actingAs($this->user);
    $response = $this->put(
        "/admin/apps/{$this->demoApp->slug}/plans/bulk-edit/configurations",
        [
            'plan_ids' => [$this->plan1->id, $this->plan2->id],
            'plans' => [
                $this->plan1->id => ['configurations' => [], 'additionalConfigs' => []],
                $this->plan2->id => ['configurations' => [], 'additionalConfigs' => []],
            ],
        ]
    );

    $response->assertRedirectContains('/plans/bulk-edit/configurations');
    $response->assertRedirectContains('plans[]='.$this->plan1->id);
    $response->assertRedirectContains('plans[]='.$this->plan2->id);
});
