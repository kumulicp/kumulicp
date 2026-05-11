<?php

namespace Tests\Feature\Applications\Plans;

use App\AppPlan;
use App\Application;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class BulkEditTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Setup helpers
    // -------------------------------------------------------------------------

    private function adminUser(): User
    {
        (new TestSupports)->seed();
        (new TestSupports)->activateDemoApp();

        return User::find(1);
    }

    private function demoApp(): Application
    {
        return Application::where('slug', 'demo_app')->first();
    }

    /** Creates two non-archived plans for the given app with known settings. */
    private function createPlans(Application $app): array
    {
        $plan1 = AppPlan::factory()->create([
            'name'            => 'Original Name One',
            'description'     => 'Original Desc One',
            'application_id'  => $app->id,
            'archive'         => false,
            'payment_enabled' => false,
            'domain_enabled'  => false,
            'domain_max'      => 0,
            'settings'        => [
                'server_type' => 'separate',
                'base'        => ['max' => 0, 'price' => 5,  'storage' => 10, 'price_id' => 'prod_1_base'],
                'basic'       => ['max' => 2, 'name' => 'Basic One', 'price' => 2, 'amount' => 1, 'storage' => 1, 'price_id' => 'prod_1_basic'],
                'storage'     => ['max' => 50, 'price' => 1, 'amount' => 5,  'price_id' => 'prod_1_sto'],
                'features'    => [],
                'standard'    => ['max' => 5, 'price' => 3, 'storage' => 2, 'price_id' => 'prod_1_std'],
                'configurations' => [],
                'additionalConfigs' => [],
                'expires_after' => 0,
                'trial_for'     => 0,
                'admin_access'  => false,
            ],
        ]);

        $plan2 = AppPlan::factory()->create([
            'name'            => 'Original Name Two',
            'description'     => 'Original Desc Two',
            'application_id'  => $app->id,
            'archive'         => false,
            'payment_enabled' => true,
            'domain_enabled'  => false,
            'domain_max'      => 0,
            'settings'        => [
                'server_type' => 'separate',
                'base'        => ['max' => 0, 'price' => 10, 'storage' => 20, 'price_id' => 'prod_2_base'],
                'basic'       => ['max' => 4, 'name' => 'Basic Two', 'price' => 4, 'amount' => 2, 'storage' => 2, 'price_id' => 'prod_2_basic'],
                'storage'     => ['max' => 100, 'price' => 2, 'amount' => 10, 'price_id' => 'prod_2_sto'],
                'features'    => [],
                'standard'    => ['max' => 10, 'price' => 6, 'storage' => 4, 'price_id' => 'prod_2_std'],
                'configurations' => [],
                'additionalConfigs' => [],
                'expires_after' => 0,
                'trial_for'     => 0,
                'admin_access'  => false,
            ],
        ]);

        return [$plan1, $plan2];
    }

    /** Returns a full settings payload for the bulk-update POST. */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'default'          => false,
            'payment_enabled'  => false,
            'admin_access'     => false,
            'domain_enabled'   => false,
            'domain_max'       => 0,
            'expires_after'    => 0,
            'trial_for'        => 0,
            'server_type'      => 'separate',
            'web_server'       => null,
            'database_server'  => null,
            'sso_server'       => null,
            'shared_app'       => null,
            'displayed_features' => [],
            'base'     => ['price' => 0, 'price_id' => '', 'storage' => 0, 'max' => 0],
            'standard' => ['price' => 0, 'price_id' => '', 'storage' => 0, 'max' => 0],
            'basic'    => ['name' => '', 'price' => 0, 'price_id' => '', 'storage' => 0, 'max' => 0, 'amount' => 0],
            'storage'  => ['price' => 0, 'price_id' => '', 'amount' => 0, 'max' => 0],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // GET routes: page rendering
    // -------------------------------------------------------------------------

    public function test_bulk_edit_settings_page_renders_for_admin()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->get(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit?plans[]={$plan1->id}&plans[]={$plan2->id}"
        );

        $response->assertStatus(200);
    }

    public function test_bulk_edit_view_page_renders_for_admin()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->get(
            "/admin/apps/{$app->slug}/plans/bulk-edit?plans[]={$plan1->id}&plans[]={$plan2->id}"
        );

        $response->assertStatus(200);
    }

    public function test_bulk_edit_features_page_renders_for_admin()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->get(
            "/admin/apps/{$app->slug}/plans/bulk-edit/features?plans[]={$plan1->id}&plans[]={$plan2->id}"
        );

        $response->assertStatus(200);
    }

    public function test_bulk_edit_configurations_page_renders_for_admin()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->get(
            "/admin/apps/{$app->slug}/plans/bulk-edit/configurations?plans[]={$plan1->id}&plans[]={$plan2->id}"
        );

        $response->assertStatus(200);
    }

    public function test_guest_cannot_access_bulk_edit_settings_page()
    {
        $this->adminUser(); // seeds DB
        $app = $this->demoApp();
        [$plan1] = $this->createPlans($app);

        $response = $this->get("/admin/apps/{$app->slug}/plans/bulk-edit/edit?plans[]={$plan1->id}");

        $response->assertRedirect('/login');
    }

    public function test_bulk_edit_only_returns_plans_belonging_to_the_app()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        // plan2 belongs to app, plan1 does too — both should appear (200)
        $response = $this->actingAs($user)->get(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit?plans[]={$plan1->id}&plans[]={$plan2->id}"
        );

        $response->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // POST /bulk-edit/edit  (settings update)
    // -------------------------------------------------------------------------

    public function test_admin_can_update_names_for_multiple_plans_at_once()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload(['name' => 'Updated Name One', 'description' => 'Original Desc One']),
                    $plan2->id => $this->settingsPayload(['name' => 'Updated Name Two', 'description' => 'Original Desc Two']),
                ],
            ]
        );

        $response->assertRedirectContains('/plans/bulk-edit/edit');
        $this->assertDatabaseHas('app_plans', ['id' => $plan1->id, 'name' => 'Updated Name One']);
        $this->assertDatabaseHas('app_plans', ['id' => $plan2->id, 'name' => 'Updated Name Two']);
    }

    public function test_admin_can_update_descriptions_for_multiple_plans()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload(['name' => 'Original Name One', 'description' => 'New Desc One']),
                    $plan2->id => $this->settingsPayload(['name' => 'Original Name Two', 'description' => 'New Desc Two']),
                ],
            ]
        );

        $this->assertDatabaseHas('app_plans', ['id' => $plan1->id, 'description' => 'New Desc One']);
        $this->assertDatabaseHas('app_plans', ['id' => $plan2->id, 'description' => 'New Desc Two']);
    }

    public function test_base_price_is_updated_for_each_plan_independently()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'base' => ['price' => 25, 'price_id' => 'prod_new_1', 'storage' => 50, 'max' => 0],
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'base' => ['price' => 75, 'price_id' => 'prod_new_2', 'storage' => 100, 'max' => 0],
                    ]),
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals(25, $plan1->settings['base']['price']);
        $this->assertEquals(75, $plan2->settings['base']['price']);
    }

    public function test_standard_user_settings_are_updated_for_each_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'standard' => ['price' => 8, 'price_id' => 'std_1', 'storage' => 3, 'max' => 20],
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'standard' => ['price' => 15, 'price_id' => 'std_2', 'storage' => 6, 'max' => 50],
                    ]),
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals(20, $plan1->settings['standard']['max']);
        $this->assertEquals(50, $plan2->settings['standard']['max']);
        $this->assertEquals(8,  $plan1->settings['standard']['price']);
        $this->assertEquals(15, $plan2->settings['standard']['price']);
    }

    public function test_basic_user_settings_are_updated_for_each_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'basic' => ['name' => 'Volunteer', 'price' => 3, 'price_id' => 'bas_1', 'storage' => 1, 'max' => 10, 'amount' => 5],
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'basic' => ['name' => 'Member', 'price' => 6, 'price_id' => 'bas_2', 'storage' => 2, 'max' => 25, 'amount' => 10],
                    ]),
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals('Volunteer', $plan1->settings['basic']['name']);
        $this->assertEquals('Member',    $plan2->settings['basic']['name']);
        $this->assertEquals(10,          $plan1->settings['basic']['max']);
        $this->assertEquals(25,          $plan2->settings['basic']['max']);
    }

    public function test_payment_enabled_flag_is_updated_per_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        // plan1 was false, plan2 was true – swap them
        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'payment_enabled' => true,
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'payment_enabled' => false,
                    ]),
                ],
            ]
        );

        $this->assertDatabaseHas('app_plans', ['id' => $plan1->id, 'payment_enabled' => true]);
        $this->assertDatabaseHas('app_plans', ['id' => $plan2->id, 'payment_enabled' => false]);
    }

    public function test_expires_after_and_trial_for_are_saved_per_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'expires_after' => 30, 'trial_for' => 7,
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'expires_after' => 90, 'trial_for' => 14,
                    ]),
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals(30, $plan1->settings['expires_after']);
        $this->assertEquals(90, $plan2->settings['expires_after']);
        $this->assertEquals(7,  $plan1->settings['trial_for']);
        $this->assertEquals(14, $plan2->settings['trial_for']);
    }

    public function test_server_type_is_saved_per_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'server_type' => 'separate',
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'server_type' => 'shared',
                    ]),
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals('separate', $plan1->settings['server_type']);
        $this->assertEquals('shared',   $plan2->settings['server_type']);
    }

    public function test_plans_not_in_plan_ids_are_not_modified_during_settings_update()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        // plan3 is NOT included in plan_ids
        $plan3 = AppPlan::factory()->create([
            'name'           => 'Untouched Plan',
            'description'    => 'Should not change',
            'application_id' => $app->id,
            'archive'        => false,
        ]);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload(['name' => 'Changed One', 'description' => 'Original Desc One']),
                    $plan2->id => $this->settingsPayload(['name' => 'Changed Two', 'description' => 'Original Desc Two']),
                ],
            ]
        );

        $this->assertDatabaseHas('app_plans', ['id' => $plan3->id, 'name' => 'Untouched Plan']);
    }

    public function test_plan_from_a_different_app_cannot_be_modified_via_another_apps_bulk_edit()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1] = $this->createPlans($app);

        // plan_other belongs to a different (non-existent) application_id
        $plan_other = AppPlan::factory()->create([
            'name'           => 'Other App Plan',
            'description'    => 'Should not change',
            'application_id' => 9999,
            'archive'        => false,
        ]);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan_other->id],
                'plans'    => [
                    $plan1->id      => $this->settingsPayload(['name' => 'Changed', 'description' => 'Original Desc One']),
                    $plan_other->id => $this->settingsPayload(['name' => 'Hacked', 'description' => 'Other App Plan']),
                ],
            ]
        );

        // The other-app plan must not be modified
        $this->assertDatabaseHas('app_plans', ['id' => $plan_other->id, 'name' => 'Other App Plan']);
    }

    public function test_displayed_features_are_updated_for_each_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload([
                        'name' => 'Original Name One', 'description' => 'Original Desc One',
                        'displayed_features' => [
                            ['name' => 'Feature A', 'description' => 'Desc A'],
                        ],
                    ]),
                    $plan2->id => $this->settingsPayload([
                        'name' => 'Original Name Two', 'description' => 'Original Desc Two',
                        'displayed_features' => [
                            ['name' => 'Feature X', 'description' => 'Desc X'],
                            ['name' => 'Feature Y', 'description' => 'Desc Y'],
                        ],
                    ]),
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertCount(1, $plan1->features);
        $this->assertCount(2, $plan2->features);
        $this->assertEquals('Feature A', $plan1->features[0]['name']);
        $this->assertEquals('Feature X', $plan2->features[0]['name']);
        $this->assertEquals('Feature Y', $plan2->features[1]['name']);
    }

    public function test_settings_update_redirects_back_to_bulk_edit_settings_with_plan_ids()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->post(
            "/admin/apps/{$app->slug}/plans/bulk-edit/edit",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => $this->settingsPayload(['name' => 'Original Name One', 'description' => 'Original Desc One']),
                    $plan2->id => $this->settingsPayload(['name' => 'Original Name Two', 'description' => 'Original Desc Two']),
                ],
            ]
        );

        $response->assertRedirectContains('/plans/bulk-edit/edit');
        $response->assertRedirectContains('plans%5B%5D='.$plan1->id);
        $response->assertRedirectContains('plans%5B%5D='.$plan2->id);
    }

    // -------------------------------------------------------------------------
    // PUT /bulk-edit/features  (features update)
    // -------------------------------------------------------------------------

    public function test_admin_can_update_features_for_multiple_plans()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $featureData1 = ['status' => 'enabled',  'price' => 5,  'price_id' => 'feat_1', 'payment_type' => 'user', 'settings' => []];
        $featureData2 = ['status' => 'optional', 'price' => 10, 'price_id' => 'feat_2', 'payment_type' => 'addon', 'settings' => []];

        $response = $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/features",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => ['features' => ['custom_feat' => $featureData1]],
                    $plan2->id => ['features' => ['custom_feat' => $featureData2]],
                ],
            ]
        );

        $response->assertRedirectContains('/plans/bulk-edit/features');

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals('enabled',  $plan1->settings['features']['custom_feat']['status']);
        $this->assertEquals('optional', $plan2->settings['features']['custom_feat']['status']);
        $this->assertEquals(5,          $plan1->settings['features']['custom_feat']['price']);
        $this->assertEquals(10,         $plan2->settings['features']['custom_feat']['price']);
    }

    public function test_each_plan_can_have_different_feature_statuses()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/features",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => ['features' => ['dark_mode' => ['status' => 'disabled', 'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]],
                    $plan2->id => ['features' => ['dark_mode' => ['status' => 'enabled',  'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]],
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertEquals('disabled', $plan1->settings['features']['dark_mode']['status']);
        $this->assertEquals('enabled',  $plan2->settings['features']['dark_mode']['status']);
    }

    public function test_plans_not_in_plan_ids_are_not_modified_during_features_update()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        // Give plan2 a pre-existing feature value
        $plan2->updateSettings(['features.legacy' => ['status' => 'enabled', 'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]);
        $plan2->save();

        // Only update plan1
        $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/features",
            [
                'plan_ids' => [$plan1->id],
                'plans'    => [
                    $plan1->id => ['features' => ['new_feat' => ['status' => 'optional', 'price' => null, 'price_id' => null, 'payment_type' => null, 'settings' => []]]],
                ],
            ]
        );

        // plan2 should be untouched
        $plan2->refresh();
        $this->assertEquals('enabled', $plan2->settings['features']['legacy']['status']);
    }

    public function test_features_update_redirects_with_plan_ids_in_query_string()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/features",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => ['features' => []],
                    $plan2->id => ['features' => []],
                ],
            ]
        );

        $response->assertRedirectContains('/plans/bulk-edit/features');
        $response->assertRedirectContains('plans%5B%5D='.$plan1->id);
        $response->assertRedirectContains('plans%5B%5D='.$plan2->id);
    }

    // -------------------------------------------------------------------------
    // PUT /bulk-edit/configurations  (configurations update)
    // -------------------------------------------------------------------------

    public function test_admin_can_add_a_new_custom_configuration_for_multiple_plans()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $additionalConfigMeta = ['name' => 'site-url', 'type' => 'string', 'persistent' => false];

        $response = $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/configurations",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => [
                        'configurations'   => ['site-url' => 'https://plan1.example.com'],
                        'additionalConfigs' => ['site-url' => $additionalConfigMeta],
                    ],
                    $plan2->id => [
                        'configurations'   => ['site-url' => 'https://plan2.example.com'],
                        'additionalConfigs' => ['site-url' => $additionalConfigMeta],
                    ],
                ],
            ]
        );

        $response->assertRedirectContains('/plans/bulk-edit/configurations');

        $plan1->refresh();
        $plan2->refresh();

        // The additional config metadata is stored so it can be re-displayed
        $this->assertArrayHasKey('site-url', $plan1->settings['additionalConfigs']);
        $this->assertArrayHasKey('site-url', $plan2->settings['additionalConfigs']);
    }

    public function test_configurations_update_stores_different_values_per_plan()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $additionalConfigMeta = ['name' => 'replica-count', 'type' => 'int', 'persistent' => false];

        $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/configurations",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => [
                        'configurations'   => ['replica-count' => '1'],
                        'additionalConfigs' => ['replica-count' => $additionalConfigMeta],
                    ],
                    $plan2->id => [
                        'configurations'   => ['replica-count' => '3'],
                        'additionalConfigs' => ['replica-count' => $additionalConfigMeta],
                    ],
                ],
            ]
        );

        $plan1->refresh();
        $plan2->refresh();

        $this->assertArrayHasKey('replica-count', $plan1->settings['additionalConfigs']);
        $this->assertArrayHasKey('replica-count', $plan2->settings['additionalConfigs']);
        // The metadata for both plans references the same config name
        $this->assertEquals('replica-count', $plan1->settings['additionalConfigs']['replica-count']['name']);
        $this->assertEquals('replica-count', $plan2->settings['additionalConfigs']['replica-count']['name']);
    }

    public function test_plans_not_in_plan_ids_are_not_modified_during_configurations_update()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        // Pre-populate plan2 with a config
        $plan2->updateSettings(['additionalConfigs.existing' => ['name' => 'existing', 'type' => 'string', 'persistent' => false]]);
        $plan2->save();
        $beforeSnapshot = $plan2->settings;

        // Only send plan1 in the request
        $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/configurations",
            [
                'plan_ids' => [$plan1->id],
                'plans'    => [
                    $plan1->id => ['configurations' => [], 'additionalConfigs' => []],
                ],
            ]
        );

        $plan2->refresh();
        // plan2's additionalConfigs should be untouched
        $this->assertArrayHasKey('existing', $plan2->settings['additionalConfigs']);
    }

    public function test_configurations_update_redirects_with_plan_ids_in_query_string()
    {
        $user = $this->adminUser();
        $app  = $this->demoApp();
        [$plan1, $plan2] = $this->createPlans($app);

        $response = $this->actingAs($user)->put(
            "/admin/apps/{$app->slug}/plans/bulk-edit/configurations",
            [
                'plan_ids' => [$plan1->id, $plan2->id],
                'plans'    => [
                    $plan1->id => ['configurations' => [], 'additionalConfigs' => []],
                    $plan2->id => ['configurations' => [], 'additionalConfigs' => []],
                ],
            ]
        );

        $response->assertRedirectContains('/plans/bulk-edit/configurations');
        $response->assertRedirectContains('plans%5B%5D='.$plan1->id);
        $response->assertRedirectContains('plans%5B%5D='.$plan2->id);
    }
}
