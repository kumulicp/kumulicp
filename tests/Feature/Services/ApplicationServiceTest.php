<?php

namespace Tests\Feature\Services;

use App\AppPlan;
use App\AppVersion;
use App\Integrations\Applications\AppProfile;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\Services\AppInstanceService;
use App\Services\Application\AppPlanService;
use App\Support\Facades\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_service()
    {
        $support = new TestSupports;
        $support->seed();

        $organization = Organization::find(1);
        $this->assertFalse(Application::isRegistered('demo_app'));

        Application::register(new DemoAppProfile);
        $this->assertTrue(Application::isRegistered('demo_app'));

        $app = Application::initialize('demo_app');

        $this->assertInstanceOf(\App\Application::class, $app);

        $this->assertEquals(1, count(Application::roles('demo_app')));

        $app_plan = AppPlan::factory()->create();

        $version = AppVersion::factory()->create([
            'application_id' => $app->id,
        ]);

        $this->assertFalse(Application::processConfigurations($app, $app_plan, [])['fake-config']);
        $this->assertTrue(Application::processConfigurations($app, $app_plan, ['fake-config' => true])['fake-config']);

        $this->assertEquals(1, count(Application::configurations($app)));
        $this->assertIsArray(Application::profile($app)->configuration('fake-config'));

        $this->assertEquals('boolean', Arr::get(Application::validateConfigurations($app), 'configurations.fake-config'));

        Application::persistentConfigurations($app, $app_plan);

        $this->assertTrue(! is_null(\App\Application::where('slug', 'demo_app')->first()));

        $demo_app = Application::profile('demo_app');
        $this->assertInstanceOf(AppProfile::class, $demo_app);

        $app_instance = Application::activate($organization, $app, $version, $app_plan);
        AddLdapGroups::dispatch($app_instance->get());
        $this->assertInstanceOf(AppInstanceService::class, $app_instance);
        Application::instance($app_instance->get())->status = 'active';
        Application::instance($app_instance->get())->save();

        $app_instance = Application::instance($app_instance->get());
        $this->assertInstanceOf(AppInstanceService::class, $app_instance);

        $this->assertInstanceOf(AppPlanService::class, Application::plan($app_plan));

        $this->assertEquals(1, count(Application::instances($organization, 'demo_app')));

        /* TODO:
        Application::runJob(AppInstance $app_instance, string $job_name);
        Application::availableParents(Application $app);

        Application::availablePlans(Application $app, Organization $organization);*/
    }
}
