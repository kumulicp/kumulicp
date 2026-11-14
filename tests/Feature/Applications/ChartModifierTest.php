<?php

use App\AppInstance;
use App\AppPlan;
use App\AppVersion;
use App\Integrations\Applications\ChartModifier;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\NextcloudJobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application;

// Registered on the profile for the rest of the process, so it only acts on
// instances whose plan turns it on
class FakeChartModifier extends ChartModifier
{
    public function values(array $values, AppInstance $app_instance): array
    {
        if (Application::instance($app_instance)->configuration('fake-chart-modifier')) {
            $values['nextcloud']['modified'] = true;
        }

        return $values;
    }

    public function job(array $job, AppInstance $app_instance): array
    {
        if (Application::instance($app_instance)->configuration('fake-chart-modifier')) {
            $job['modified'] = true;
        }

        return $job;
    }
}

function chartModifierInstance(bool $enabled): array
{
    $organization = Organization::factory()->create();

    $app = Application::initialize('nextcloud');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'chart_name' => 'nextcloud',
            'chart_version' => '1.0.0',
            'helm_repo_name' => 'https://nextcloud.github.io/helm',
            'image_repo_name' => 'library/nextcloud',
            'image_registry' => 'docker.io',
        ],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => ['fake-chart-modifier' => $enabled],
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();
    $web_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => Server::factory()->create()->id]);
    $app_instance->web_server_id = $web_server->id;
    $app_instance->save();

    return [$organization, $app_instance->fresh()];
}

it('lets an extension change the values of the release', function () {
    Application::profile('nextcloud')->addChartModifier(FakeChartModifier::class);

    [$organization, $app_instance] = chartModifierInstance(true);
    $chart = new NextcloudChart($organization, $app_instance);

    expect($chart->valuesWithAdditionalConfigs()['nextcloud']['modified'])->toBeTrue();
});

it('leaves the values alone when the extension does not apply', function () {
    Application::profile('nextcloud')->addChartModifier(FakeChartModifier::class);

    [$organization, $app_instance] = chartModifierInstance(false);
    $chart = new NextcloudChart($organization, $app_instance);

    expect($chart->valuesWithAdditionalConfigs()['nextcloud'])->not->toHaveKey('modified');
});

it('lets an extension change a job run against the release', function () {
    Application::profile('nextcloud')->addChartModifier(FakeChartModifier::class);

    [$organization, $app_instance] = chartModifierInstance(true);

    expect((new NextcloudJobChart($organization, $app_instance))->modifyJob(['kind' => 'Job']))
        ->toBe(['kind' => 'Job', 'modified' => true]);
});

it('registers an extension only once and ignores classes that do not exist', function () {
    $profile = Application::profile('nextcloud');
    $profile->addChartModifier(FakeChartModifier::class)->addChartModifier(FakeChartModifier::class)->addChartModifier('Missing\\Modifier');

    expect(array_count_values($profile->chartModifiers())[FakeChartModifier::class])->toBe(1)
        ->and($profile->chartModifiers())->not->toContain('Missing\\Modifier');
});

it('lets an extension add a configuration to an app', function () {
    Application::profile('nextcloud')->addConfiguration('extension-setting', ['name' => 'extension-setting', 'type' => 'bool', 'default' => true]);

    expect(Application::profile('nextcloud')->configuration('extension-setting')['default'])->toBeTrue();
});
