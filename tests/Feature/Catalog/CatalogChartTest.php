<?php

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\ChartSource;
use App\Integrations\Applications\Catalog\CatalogChart;
use App\Organization;
use App\Support\Catalog\ChartCatalogSync;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Support\Facades\Http;
use Tests\Support\VersionUpdates\ChartArchive;

// Syncs one chart with the given default values and activates it for an organization
function catalogInstance(array $values, array $plan_configurations = []): array
{
    $source = ChartSource::where('slug', 'helmforge')->first();
    $source->update(['url' => 'https://repo.test']);

    Http::fake([
        'repo.test/index.yaml' => Http::response(ChartArchive::index('gitea', ['1.0.0'])),
        'repo.test/gitea-1.0.0.tgz' => Http::response(ChartArchive::make('gitea', '1.0.0', $values)),
    ]);

    app(ChartCatalogSync::class)->sync($source);

    $organization = Organization::factory()->create();
    $application = Application::where('slug', 'gitea')->first();
    $version = AppVersion::where('application_id', $application->id)->first();
    $plan = AppPlan::factory()->create([
        'application_id' => $application->id,
        'settings' => ['configurations' => $plan_configurations],
    ]);

    $app_instance = ApplicationFacade::activate($organization, $application, $version, $plan)->get();

    return [new CatalogChart($organization, $app_instance), $app_instance];
}

function giteaDefaults(): array
{
    return [
        'replicaCount' => 1,
        'fullnameOverride' => '',
        'imagePullSecrets' => [],
        'persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => ''],
        'resources' => ['requests' => ['cpu' => '100m', 'memory' => '128Mi']],
        'ingress' => ['enabled' => false, 'annotations' => [], 'hosts' => [], 'tls' => []],
        'podLabels' => [],
        'admin' => ['password' => ''],
    ];
}

it('writes Kubernetes settings to the keys the chart has', function () {
    [$chart, $app_instance] = catalogInstance(giteaDefaults(), ['ingress-tls' => true]);

    $values = $chart->valuesWithAdditionalConfigs();
    $domain = $app_instance->domain();

    expect($values['replicaCount'])->toBe(1);
    expect($values['fullnameOverride'])->toBe($chart->organization->slug.'-'.$chart->chartName());
    expect($values['persistence']['size'])->toMatch('/^[1-9]\d*Gi$/');
    expect($values['resources'])->toBe(['requests' => ['cpu' => '100m', 'memory' => '128Mi']]);
    expect($values['admin']['password'])->toBe($app_instance->api_password());
    expect($values['ingress']['enabled'])->toBeTrue();
    expect($values['ingress']['hosts'])->toBe([['host' => $domain, 'paths' => [['path' => '/', 'pathType' => 'Prefix']]]]);
    expect($values['ingress']['tls'])->toBe([['secretName' => $chart->chartName().'-ingress-tls', 'hosts' => [$domain]]]);
    expect($values['ingress']['annotations']['cert-manager.io/cluster-issuer'])->toBe('letsencrypt-production');
});

it('labels the workload with the app and instance', function () {
    [$chart, $app_instance] = catalogInstance(giteaDefaults());

    expect($chart->valuesWithAdditionalConfigs()['podLabels'])->toBe([
        'kumulicp.io/app' => 'gitea',
        'kumulicp.io/instance' => $app_instance->name,
        'kumulicp.io/type' => 'app',
    ]);
});

it('only sends the chart keys the plan sets', function () {
    [$chart] = catalogInstance(giteaDefaults(), ['resources-limits-memory' => '1Gi', 'replicaCount' => 0]);

    $values = $chart->valuesWithAdditionalConfigs();

    expect($values['resources'])->toBe(['requests' => ['cpu' => '100m', 'memory' => '128Mi'], 'limits' => ['memory' => '1Gi']]);
    expect($values['replicaCount'])->toBe(0);
});

it('leaves out settings for concerns the chart does not have', function () {
    [$chart] = catalogInstance(['image' => ['tag' => '1']]);

    expect(array_keys($chart->valuesWithAdditionalConfigs()))->toBe([]);
});

it('names the release after the chart and the instance', function () {
    [$chart, $app_instance] = catalogInstance(giteaDefaults());

    expect($chart->chart_name)->toBe('gitea');
    expect($chart->chartName())->toBe($app_instance->name);
    expect($app_instance->version->setting('chart_name'))->toBe('gitea');
});
