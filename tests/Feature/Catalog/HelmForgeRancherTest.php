<?php

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\ChartSource;
use App\Integrations\Applications\HelmForge\HelmForgeChart;
use App\Integrations\Applications\HelmForge\HelmForgeProfile;
use App\Integrations\ServerManagers\Rancher\API\Application as RancherApplication;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Catalog\ChartCatalogSync;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\VersionUpdates\ChartArchive;

// A catalog chart on a Rancher server is deployed from the repository as it was
// added to Rancher, by the name it was given there, instead of from its URL

function rancherCatalogInstance(): array
{
    $source = ChartSource::where('slug', 'helmforge')->first();
    $source->update(['url' => 'https://repo.test']);

    Http::fake([
        'repo.test/index.yaml' => Http::response(ChartArchive::index('gitea', ['1.0.0'])),
        'repo.test/gitea-1.0.0.tgz' => Http::response(ChartArchive::make('gitea', '1.0.0', ['replicaCount' => 1, 'fullnameOverride' => ''])),
    ]);
    app(ChartCatalogSync::class)->sync($source);

    $organization = Organization::factory()->create();
    $application = Application::where('slug', 'gitea')->first();
    $version = AppVersion::where('application_id', $application->id)->first();
    $plan = AppPlan::factory()->create(['application_id' => $application->id]);

    $app_instance = ApplicationFacade::activate($organization, $application, $version, $plan)->get();

    return [$organization, $application, $app_instance];
}

it('is compatible with Rancher', function () {
    [, $application] = rancherCatalogInstance();

    expect(ApplicationFacade::profile($application))->toBeInstanceOf(HelmForgeProfile::class)
        ->and(ApplicationFacade::profile($application)->isCompatible('rancher'))->toBeTrue();
});

it('installs through Rancher from the repository name its chart is given', function () {
    [$organization, $application, $app_instance] = rancherCatalogInstance();

    // What the admin enters for the chart, once the repository is added to Rancher
    $application->charts()->where('is_default', true)->first()->update(['helm_repo' => 'helmforge-in-rancher']);

    $server = Server::factory()->create([
        'interface' => 'rancher',
        'address' => 'https://rancher.example.com',
        'api_key' => 'token-abc',
        'api_secret' => 'secret-xyz',
        'settings' => ['project_id' => 'p-1'],
    ]);
    $org_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);

    Http::fake(['rancher.example.com/*' => Http::response(['ok' => true])]);

    $result = (new RancherApplication($organization, $org_server))->create($app_instance, new HelmForgeChart($organization, $app_instance));

    expect($result['status'])->toBe('success');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://rancher.example.com/v1/catalog.cattle.io.clusterrepos/helmforge-in-rancher?action=install'
        && $request['charts'][0]['chartName'] === 'gitea'
        && $request['charts'][0]['version'] === '1.0.0');
});
