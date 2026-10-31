<?php

use App\Application;
use App\AppVersion;
use App\ChartSource;
use App\Enums\ApplicationSource;
use App\Integrations\Applications\HelmForge\HelmForgeProfile;
use App\Support\Catalog\ChartCatalogSync;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\VersionUpdates\ChartArchive;

function catalogIndex(array $charts): string
{
    $entries = [];

    foreach ($charts as $name => $versions) {
        $entries[$name] = array_map(fn ($version) => [
            'name' => $name,
            'version' => $version,
            'description' => "The $name app",
            'urls' => ["$name-$version.tgz"],
        ], $versions);
    }

    return Yaml::dump(['apiVersion' => 'v1', 'entries' => $entries], 6);
}

function fakeCatalog(array $charts, array $values = []): void
{
    $fakes = ['repo.test/index.yaml' => Http::response(catalogIndex($charts))];

    foreach ($charts as $name => $versions) {
        foreach ($versions as $version) {
            $fakes["repo.test/$name-$version.tgz"] = Http::response(ChartArchive::make($name, $version, $values[$name] ?? ['replicaCount' => 1]));
        }
    }

    // A fresh client each time: stubs registered earlier would otherwise win,
    // and the recorded requests would carry over
    Http::swap(new HttpFactory);
    Http::fake($fakes);
}

beforeEach(function () {
    $this->source = ChartSource::where('slug', 'helmforge')->first();
    $this->source->update(['url' => 'https://repo.test']);
});

it('imports every chart as a disabled app with its newest versions', function () {
    fakeCatalog(['gitea' => ['2.0.0', '1.9.0', '1.8.0', '1.7.0'], 'redis' => ['3.0.0']]);

    $report = app(ChartCatalogSync::class)->sync($this->source);

    expect($report['created'])->toBe(['gitea', 'redis']);

    $gitea = Application::where('slug', 'gitea')->first();
    expect($gitea->enabled)->toBeFalsy();
    expect($gitea->chart_source_id)->toBe($this->source->id);
    expect($gitea->source)->toBe(ApplicationSource::HelmForge);
    expect($gitea->description)->toBe('The gitea app');
    expect($gitea->catalog['chart_version'])->toBe('2.0.0');

    $versions = AppVersion::where('application_id', $gitea->id)->orderBy('id')->get();
    expect($versions->pluck('name')->all())->toBe(['2.0.0', '1.9.0', '1.8.0']);
    expect($versions->first()->status)->toBe('deactivated');
    expect($versions->first()->settings)->toBe([
        'chart_name' => 'gitea',
        'chart_version' => '2.0.0',
        'helm_repo_name' => 'https://repo.test',
    ]);
    expect($this->source->fresh()->last_synced_at)->not->toBeNull();
});

it('is idempotent and keeps admin edits', function () {
    fakeCatalog(['gitea' => ['2.0.0']]);
    app(ChartCatalogSync::class)->sync($this->source);

    $gitea = Application::where('slug', 'gitea')->first();
    $gitea->name = 'Our Git';
    $gitea->enabled = true;
    $gitea->save();

    Http::swap(new HttpFactory);
    Http::fake(['repo.test/index.yaml' => Http::response(catalogIndex(['gitea' => ['2.0.0']]))]);
    $report = app(ChartCatalogSync::class)->sync($this->source);

    expect($report['updated'])->toBe(['gitea']);
    expect($gitea->fresh()->name)->toBe('Our Git');
    expect($gitea->fresh()->enabled)->toBeTruthy();
    expect(AppVersion::where('application_id', $gitea->id)->count())->toBe(1);
    // Unchanged chart version: the package wasn't downloaded again
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '.tgz'));
});

it('re-detects the mapping when a chart has a new version', function () {
    fakeCatalog(['gitea' => ['1.0.0']], ['gitea' => ['replicaCount' => 1]]);
    app(ChartCatalogSync::class)->sync($this->source);
    expect(Application::where('slug', 'gitea')->first()->catalog['convention']['resources'])->toBeNull();

    fakeCatalog(['gitea' => ['1.1.0', '1.0.0']], ['gitea' => ['replicaCount' => 1, 'resources' => []]]);
    app(ChartCatalogSync::class)->sync($this->source);

    $gitea = Application::where('slug', 'gitea')->first();
    expect($gitea->catalog['chart_version'])->toBe('1.1.0');
    expect($gitea->catalog['convention']['resources'])->toBe('resources');
});

it('skips excluded charts and apps that already exist', function () {
    Application::factory()->create(['slug' => 'ours']);
    fakeCatalog(['generic' => ['1.0.0'], 'ours' => ['1.0.0'], 'wordpress' => ['1.0.0'], 'gitea' => ['1.0.0']]);

    $report = app(ChartCatalogSync::class)->sync($this->source);

    expect($report['created'])->toBe(['gitea']);
    expect($report['skipped'])->toBe([
        'generic' => 'excluded',
        'ours' => 'an app with this slug already exists',
        'wordpress' => 'shadowed by a built-in profile',
    ]);
});

it('keeps going when one chart fails', function () {
    Http::fake([
        'repo.test/index.yaml' => Http::response(catalogIndex(['broken' => ['1.0.0'], 'gitea' => ['1.0.0']])),
        'repo.test/broken-1.0.0.tgz' => Http::response('not a chart'),
        'repo.test/gitea-1.0.0.tgz' => Http::response(ChartArchive::make('gitea', '1.0.0', ['replicaCount' => 1])),
    ]);

    $report = app(ChartCatalogSync::class)->sync($this->source);

    expect($report['created'])->toBe(['gitea']);
    expect(array_keys($report['failed']))->toBe(['broken']);
    expect(Application::where('slug', 'broken')->exists())->toBeFalse();
});

it('records an unreachable repository on the source', function () {
    Http::fake(['repo.test/index.yaml' => Http::response('nope', 500)]);

    $report = app(ChartCatalogSync::class)->sync($this->source);

    expect($report['error'])->not->toBeNull();
    expect($this->source->fresh()->last_sync_error)->not->toBeNull();
});

it('flags charts missing core concerns as limited', function () {
    fakeCatalog(['gitea' => ['1.0.0']], ['gitea' => ['image' => ['tag' => '1']]]);

    $report = app(ChartCatalogSync::class)->sync($this->source);

    expect($report['limited']['gitea'])->toBe(['ingress', 'persistence', 'replicas', 'resources']);
});

it('resolves a synced app to a HelmForge profile with its detected options', function () {
    fakeCatalog(['gitea' => ['1.0.0']], ['gitea' => ['replicaCount' => 2, 'resources' => []]]);
    app(ChartCatalogSync::class)->sync($this->source);

    $profile = ApplicationFacade::profile('gitea');

    expect($profile)->toBeInstanceOf(HelmForgeProfile::class);
    expect($profile->name())->toBe('gitea');
    expect($profile->isCompatible('helm_chart'))->toBeTrue();
    expect($profile->isCompatible('rancher'))->toBeFalse();
    expect($profile->configuration('replicaCount')['default'])->toBe(2);
    expect(ApplicationFacade::profile('unknown-app')->name())->toBe('generic');
});

it('routes an app to the channel of its source', function () {
    Application::factory()->create(['slug' => 'added-in-ui', 'source' => ApplicationSource::Generic]);
    // Same catalog data as a synced app, but it was not imported from HelmForge
    Application::factory()->create(['slug' => 'not-helmforge', 'source' => ApplicationSource::Generic, 'catalog' => ['configurations' => []]]);

    expect(ApplicationFacade::profile('added-in-ui')->name())->toBe('generic');
    expect(ApplicationFacade::profile('not-helmforge')->name())->toBe('generic');
});

it('lets a registered profile win over the source', function () {
    $nextcloud = Application::factory()->create(['slug' => 'nextcloud', 'source' => ApplicationSource::HelmForge, 'catalog' => ['configurations' => []]]);

    expect(ApplicationFacade::profile($nextcloud)->name())->toBe('nextcloud');
});

it('marks apps created for a registered profile as profile apps', function () {
    $application = ApplicationFacade::initialize('wordpress');

    expect($application->fresh()->source)->toBe(ApplicationSource::Profile);
});

it('does not sync a chart source without a supported chart schema', function () {
    $other = ChartSource::create(['name' => 'Cloud Pirates', 'slug' => 'cloudpirates', 'url' => 'https://repo.test']);
    fakeCatalog(['gitea' => ['1.0.0']]);

    $report = app(ChartCatalogSync::class)->sync($other);

    expect($report['error'])->toContain('cloudpirates');
    expect($report['created'])->toBe([]);
    expect(Application::where('slug', 'gitea')->exists())->toBeFalse();
});
