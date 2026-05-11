<?php

namespace Tests\Feature\Applications;

use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\Support\Facades\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Applications\DemoHelmChart;
use Tests\TestCase;

class AppConfigurationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_configurations_features_and_helm_chart_values()
    {
        $organization = Organization::factory()->create();

        Application::register(new DemoAppProfile);
        $app = Application::initialize('demo_app');
        Application::roles($app);

        $roles = [];
        foreach ($app->roles()->get() as $role) {
            $roles[] = $role->id;
        }

        $version = AppVersion::factory()->create(['application_id' => $app->id]);
        $version->roles = ['order' => $roles];
        $version->save();

        $app_plan = AppPlan::factory()->create([
            'application_id' => $app->id,
            'settings' => [
                'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
                'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
                'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
                'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
                'features' => [
                    'enabled-feature' => [
                        'name' => 'enabled-feature',
                        'price' => null,
                        'status' => 'enabled',
                        'settings' => [],
                        'price_id' => null,
                    ],
                    'optional-feature' => [
                        'name' => 'optional-feature',
                        'price' => null,
                        'status' => 'optional',
                        'settings' => [],
                        'price_id' => null,
                    ],
                    'disabled-feature' => [
                        'name' => 'disabled-feature',
                        'price' => null,
                        'status' => 'disabled',
                        'settings' => [],
                        'price_id' => null,
                    ],
                ],
                'configurations' => [
                    'fake-config' => false,
                    'persistent-value' => 'plan-persistent-value',
                    'non-persistent-value' => 'plan-non-persistent-value',
                    'override-value' => 'plan-override-value',
                ],
            ],
        ]);

        // Activate the app via the Application facade
        $app_instance_service = Application::activate($organization, $app, $version, $app_plan);
        $app_instance = $app_instance_service->get();

        // Only persistent configurations should be saved to the app instance
        $this->assertEquals('plan-persistent-value', $app_instance->setting('configurations.persistent-value'));
        $this->assertNull($app_instance->setting('configurations.non-persistent-value'));
        $this->assertNull($app_instance->setting('configurations.override-value'));
        $this->assertNull($app_instance->setting('configurations.fake-config'));

        // Verify feature statuses
        $features = Application::instance($app_instance)->features();

        // Enabled feature is active (plan sets it to 'enabled')
        $this->assertTrue($features->isActive('enabled-feature'));

        // Optional feature appears in the optional list — user can toggle it
        $this->assertArrayHasKey('optional-feature', $features->optional());
        // Optional feature is not active until the user explicitly enables it
        $this->assertFalse($features->isActive('optional-feature'));

        // Disabled feature remains off
        $this->assertFalse($features->isActive('disabled-feature'));

        // Add an override configuration to the app instance
        $app_instance->updateSetting('override.override-value', 'overridden-value');
        $app_instance->refresh();

        // Retrieve helm chart values
        $chart = new DemoHelmChart($organization, $app_instance);
        $values = $chart->values();

        // Persistent value comes from the app instance (written at activation time)
        $this->assertEquals('plan-persistent-value', $values['persistentValue']);

        // Non-persistent value without an override comes straight from the plan
        $this->assertEquals('plan-non-persistent-value', $values['nonPersistentValue']);

        // Non-persistent value with an override comes from the app instance override
        $this->assertEquals('overridden-value', $values['overrideValue']);
    }
}
