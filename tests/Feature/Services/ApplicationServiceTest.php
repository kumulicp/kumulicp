<?php

use App\AppPlan;
use App\AppVersion;
use App\Integrations\Applications\AppProfile;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Services\AppInstanceService;
use App\Services\Application\AppPlanService;
use App\Support\Facades\Application;
use App\Support\Facades\Domain;
use Illuminate\Support\Arr;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;

it('manages applications via service', function () {
    $support = new TestSupports;
    $support->seed();

    $organization = Organization::find(1);
    expect(Application::isRegistered('demo_app'))->toBeFalse();

    Application::register(new DemoAppProfile);
    expect(Application::isRegistered('demo_app'))->toBeTrue();

    $app = Application::initialize('demo_app');

    expect($app)->toBeInstanceOf(App\Application::class);
    expect(count(Application::roles('demo_app')))->toBe(1);

    $app_plan = AppPlan::factory()->create(['application_id' => $app->id]);

    AppVersion::factory()->create([
        'application_id' => $app->id,
    ]);

    expect(Application::processConfigurations($app, $app_plan, [])['fake-config'])->toBeFalse();
    expect(Application::processConfigurations($app, $app_plan, ['fake-config' => true])['fake-config'])->toBeTrue();

    expect(count(Application::configurations($app)))->toBe(4);
    expect(Application::profile($app)->configuration('fake-config'))->toBeArray();

    expect(Arr::get(Application::validateConfigurations($app), 'configurations.fake-config'))->toBe('boolean');

    Application::persistentConfigurations($app, $app_plan);

    expect(App\Application::where('slug', 'demo_app')->first())->not->toBeNull();

    $demo_app = Application::profile('demo_app');
    expect($demo_app)->toBeInstanceOf(AppProfile::class);

    $version = AppVersion::where('application_id', $app->id)->first();
    $app_instance = Application::activate($organization, $app, $version, $app_plan);
    AddLdapGroups::dispatch($app_instance->get());
    expect($app_instance)->toBeInstanceOf(AppInstanceService::class);
    Application::instance($app_instance->get())->status = 'active';
    Application::instance($app_instance->get())->save();

    $app_instance = Application::instance($app_instance->get());
    expect($app_instance)->toBeInstanceOf(AppInstanceService::class);

    expect(Application::plan($app_plan))->toBeInstanceOf(AppPlanService::class);
    expect(count(Application::instances($organization, 'demo_app')))->toBe(1);
});

it('makes a subdomain created for an activation an app subdomain once the app owns it', function () {
    $support = new TestSupports;
    $support->seed();

    $organization = Organization::find(1);
    Application::register(new DemoAppProfile);
    $app = Application::initialize('demo_app');
    $app_plan = AppPlan::factory()->create(['application_id' => $app->id]);
    $version = AppVersion::factory()->create(['application_id' => $app->id]);

    $parent = OrgDomain::factory()->create(['organization_id' => $organization->id, 'type' => 'managed']);

    // What the Discover activation page does: the subdomain exists before the app does
    $subdomain = Domain::addSubdomain('erp', $parent);
    expect($subdomain->type)->toBe('none');

    $app_instance = Application::activate($organization, $app, $version, $app_plan, domain: $subdomain);

    $subdomain = OrgSubdomain::find($subdomain->id);
    expect($subdomain->type)->toBe('app');
    expect($subdomain->app_instance_id)->toBe($app_instance->get()->id);
});
