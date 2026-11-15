<?php

use App\AppInstance;
use App\AppPlan;
use App\AppVersion;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;
use App\Integrations\Applications\Nextcloud\API\Apps;
use App\Organization;
use App\Support\Facades\Application as ApplicationFacade;
use App\Task;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

function nextcloudHubWithChild(): array
{
    $app = ApplicationFacade::initialize('nextcloud');
    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $hub_plan = AppPlan::factory()->create(['application_id' => $app->id]);
    $shared_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => array_merge($hub_plan->settings ?? [], ['server_type' => 'shared']),
    ]);

    $hub = AppInstance::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'application_id' => $app->id,
        'version_id' => $version->id,
        'plan_id' => $hub_plan->id,
        'api_password' => Crypt::encrypt('hub-password'),
    ]);

    $child = AppInstance::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'application_id' => $app->id,
        'version_id' => $version->id,
        'plan_id' => $shared_plan->id,
        'parent_id' => $hub->id,
        'api_password' => Crypt::encrypt('child-password'),
    ]);

    return [$hub, $child->fresh()];
}

it('authenticates a shared child with the hub\'s credentials', function () {
    [$hub, $child] = nextcloudHubWithChild();

    expect((new Apps($child))->auth()[1])->toBe('hub-password');
    expect((new Apps($hub))->auth()[1])->toBe('hub-password');
});

it('does not run addon actions against a shared child', function () {
    Http::fake();
    [, $child] = nextcloudHubWithChild();

    $task = Mockery::mock(Task::class)->makePartial();
    $task->setRelation('app_instance', $child);
    $task->custom_values = ['name' => 'spreed', 'status' => 'enabled'];
    $task->shouldReceive('delete')->once();

    ManageAddon::run($task);

    Http::assertNothingSent();
});
