<?php

// Maintenance mode: the app's own chart turns its ingress off, and a second release
// (the "generic" chart running the wickerlabs/maintenance image) serves a page at
// the app's domain instead.

use App\AppInstance;
use App\AppPlan;
use App\AppVersion;
use App\Integrations\ServerManagers\Kubernetes\API\Application as KubernetesApplication;
use App\Integrations\ServerManagers\Kubernetes\Charts\GenericChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\MaintenanceChart;
use App\Integrations\ServerManagers\Kubernetes\Interfaces\KubernetesWebInterface;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application;

function maintenanceInstance(array $configurations = [], array $settings = []): AppInstance
{
    $organization = Organization::factory()->create(['name' => 'Acme Church']);

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'chart_name' => 'generic',
            'chart_version' => '3.1.5',
            'helm_repo_name' => 'https://repo.helmforge.dev',
            'image_repo_name' => 'library/my-app',
        ],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => array_merge(['ingress-enabled' => true, 'ingress-tls' => true], $configurations),
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();

    if ($settings) {
        $app_instance->settings = array_merge($app_instance->settings ?? [], $settings);
        $app_instance->save();
    }

    return $app_instance;
}

it('leaves the app\'s ingress on until it is put in maintenance mode', function () {
    $app_instance = maintenanceInstance();
    $organization = $app_instance->organization;

    expect($app_instance->inMaintenance())->toBeFalse();
    expect((new GenericChart($organization, $app_instance))->values()['ingress']['enabled'])->toBeTrue();

    $app_instance->updateSetting('maintenance.enabled', true);

    expect($app_instance->inMaintenance())->toBeTrue();
    expect((new GenericChart($organization, $app_instance))->values()['ingress']['enabled'])->toBeFalse();

    // Nothing else of the app changes
    $values = (new GenericChart($organization, $app_instance))->values();
    expect($values['replicaCount'])->toBe(1);
    expect($values['ingress']['hosts'][0]['host'])->toBe($app_instance->domain());
});

it('serves the maintenance image at the app\'s domain from a release of its own', function () {
    $app_instance = maintenanceInstance(['ingress-annotation-cluster_issuer' => 'my-issuer']);
    $app_instance->updateSetting('maintenance.enabled', true);

    $chart = new MaintenanceChart($app_instance->organization, $app_instance);
    $values = $chart->values();

    expect($chart->chartName())->toBe($app_instance->name.'-maintenance');
    expect($values['fullnameOverride'])->toBe($chart->chartName());

    // The generic chart, from the configured repository: not the app's own chart
    expect($chart->helmChartName())->toBe('generic');
    expect($chart->helmChartVersion())->toBe(config('maintenance.chart.version'));
    expect($chart->helmRepo())->toBe('https://repo.helmforge.dev');
    expect($chart->helmRepoSecret())->toBeNull();

    expect($values['image'])->toMatchArray(['repository' => 'docker.io/wickerlabs/maintenance', 'tag' => 'latest']);
    expect($values['containers'][0]['ports'][0]['containerPort'])->toBe(8080);
    expect($values['service'])->toMatchArray(['port' => 8080, 'targetPort' => 8080]);

    // The image listens where the chart sends traffic, and is set up for the app
    $env = collect($values['env'])->pluck('value', 'name');
    expect($env['PORT'])->toBe('8080');
    expect($env['TEAM_NAME'])->toBe('Acme Church');
    expect($env['TITLE'])->toContain($app_instance->label);

    expect($values['ingress']['enabled'])->toBeTrue();
    expect($values['ingress']['hosts'][0]['host'])->toBe($app_instance->domain());
    expect($values['ingress']['tls'][0])->toBe(['hosts' => [$app_instance->domain()], 'secretName' => $chart->chartName().'-tls']);
    expect($values['ingress']['annotations'])->toBe(['cert-manager.io/cluster-issuer' => 'my-issuer']);

    // The app's environment and secrets are not for the maintenance page
    expect(json_encode($values))->not->toContain('secretKeyRef');
});

it('leaves out the TLS of an app that has none, and what the image can\'t take', function () {
    $app_instance = maintenanceInstance(['ingress-tls' => false]);
    $app_instance->organization->forceFill(['name' => 'Smith & Sons/Co $(reboot)'])->save();
    $app_instance->updateSetting('maintenance.enabled', true);

    $values = (new MaintenanceChart($app_instance->organization->fresh(), $app_instance))->values();

    // Yaml::dump() would render these empty lists as `{}`, which the chart's schema rejects
    expect($values['ingress'])->not->toHaveKeys(['tls', 'annotations']);

    // The image puts its variables into the page with sed
    expect(collect($values['env'])->pluck('value', 'name')['TEAM_NAME'])->toBe('Smith  SonsCo reboot');
});

it('is only in maintenance while the app is enabled', function () {
    $app_instance = maintenanceInstance(settings: ['maintenance' => ['enabled' => true]]);
    $chart = new MaintenanceChart($app_instance->organization, $app_instance);

    expect($chart->inMaintenance())->toBeTrue();

    // A deactivated organization's apps have no ingress at all, so there is no page to serve
    $organization = $app_instance->organization;
    $organization->status = 'deactivated';
    $organization->save();

    expect((new MaintenanceChart($organization, $app_instance))->inMaintenance())->toBeFalse();
});

// The web interface, with the cluster's Helm releases recorded instead of installed
function maintenanceInterface(AppInstance $app_instance, array &$calls, array $releases = []): KubernetesWebInterface
{
    $server = Server::factory()->create(['interface' => 'kubernetes', 'type' => 'web']);
    $org_server = OrgServer::create(['organization_id' => $app_instance->organization_id, 'server_id' => $server->id]);

    $api = Mockery::mock(KubernetesApplication::class);
    $api->shouldReceive('isActive')->andReturnUsing(fn ($instance, $chart) => $releases[$chart->chartName()] ?? 0);
    foreach (['update', 'remove'] as $method) {
        $api->shouldReceive($method)->andReturnUsing(function ($instance, $chart) use (&$calls, $method) {
            $calls[] = "$method {$chart->chartName()}";

            return ['status' => 'success', 'response' => ''];
        });
    }

    return new class($org_server, $app_instance, $api) extends KubernetesWebInterface
    {
        public function __construct(OrgServer $server, AppInstance $app_instance, private $api)
        {
            parent::__construct($server, $app_instance);
        }

        protected function makeApplication(): KubernetesApplication
        {
            return $this->api;
        }

        public function ensureImageRegistryAuth()
        {
            return true;
        }

        public function updateRedirectDomains() {}
    };
}

it('rolls the app out with its ingress off and the maintenance page on', function () {
    $app_instance = maintenanceInstance(settings: ['maintenance' => ['enabled' => true]]);
    $name = (new GenericChart($app_instance->organization, $app_instance))->chartName();
    $calls = [];

    $interface = maintenanceInterface($app_instance, $calls, [$name => 1]);
    $interface->update();

    expect($calls)->toBe(["update $name", "update {$app_instance->name}-maintenance"]);

    // Deployed once both releases are
    $reported = [];
    expect(maintenanceInterface($app_instance, $reported, [$name => 1])->isActive())->toBeFalse();
    expect(maintenanceInterface($app_instance, $reported, [$name => 1, "{$app_instance->name}-maintenance" => 1])->isActive())->toBeTrue();
});

it('takes the maintenance page away again, but only when there is one', function () {
    $app_instance = maintenanceInstance();
    $name = (new GenericChart($app_instance->organization, $app_instance))->chartName();

    $calls = [];
    maintenanceInterface($app_instance, $calls, [$name => 1, "{$app_instance->name}-maintenance" => 1])->update();
    expect($calls)->toBe(["update $name", "remove {$app_instance->name}-maintenance"]);

    // No release to remove, so no Job to run for every update of every app
    $calls = [];
    maintenanceInterface($app_instance, $calls, [$name => 1])->update();
    expect($calls)->toBe(["update $name"]);
});

it('removes the maintenance page with the app', function () {
    $app_instance = maintenanceInstance(settings: ['maintenance' => ['enabled' => true]]);
    $name = (new GenericChart($app_instance->organization, $app_instance))->chartName();

    $calls = [];
    maintenanceInterface($app_instance, $calls, [$name => 1, "{$app_instance->name}-maintenance" => 1])->delete();

    expect($calls)->toContain("remove {$app_instance->name}-maintenance")->toContain("remove $name");
});
