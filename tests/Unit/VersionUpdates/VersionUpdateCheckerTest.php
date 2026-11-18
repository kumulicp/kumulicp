<?php

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\AppVersionImage;
use App\OrgServer;
use App\RepoSecret;
use App\Server;
use App\Support\VersionUpdates\ChartCompatibilityChecker;
use App\Support\VersionUpdates\ValuesContract;
use App\Support\VersionUpdates\VersionUpdateChecker;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSupports;
use Tests\Support\VersionUpdates\ChartArchive;

beforeEach(function () {
    (new TestSupports)->seed();

    $this->application = Application::factory()->create();
});

// Values as Kumulicp would render them for a running instance
function renderingContract(?array $values): ValuesContract
{
    return new class($values) extends ValuesContract
    {
        public function __construct(private ?array $values) {}

        public function render(AppVersion $version): ?array
        {
            return $this->values;
        }
    };
}

function checkerWith(?array $rendered_values): VersionUpdateChecker
{
    $contract = renderingContract($rendered_values);

    return new VersionUpdateChecker(
        compatibility: new ChartCompatibilityChecker($contract),
        contract: $contract,
    );
}

function runOn(Application $application, AppVersion $version, string $interface, array $server_attributes = []): Server
{
    $server = Server::factory()->create(array_merge(['interface' => $interface, 'name' => $interface.' cluster'], $server_attributes));
    $org_server = OrgServer::create(['organization_id' => 1, 'server_id' => $server->id]);

    (new AppInstance)->forceFill([
        'application_id' => $application->id,
        'organization_id' => 1,
        'version_id' => $version->id,
        'web_server_id' => $org_server->id,
        'name' => 'demo',
        'label' => 'Demo',
        'api_password' => '',
        'status' => 'active',
    ])->save();

    return $server;
}

it('offers the newest image tag and the newest chart that passes the checks', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'name' => '33.0.1',
        'settings' => [
            'image_registry' => 'docker.io',
            'image_repo_name' => 'library/nextcloud',
            'helm_repo_name' => 'https://charts.example.com',
            'chart_name' => 'nextcloud',
            'chart_version' => '9.0.0',
        ],
    ]);

    $defaults = ['image' => ['repository' => '', 'tag' => ''], 'replicaCount' => 1];

    Http::fake([
        'registry-1.docker.io/v2/library/nextcloud/tags/list*' => Http::response(['tags' => ['33.0.1', '33.0.2', '34', 'latest']]),
        'charts.example.com/index.yaml' => Http::response(ChartArchive::index('nextcloud', ['9.0.0', '9.0.1', '9.1.0', '10.0.0-rc.1'])),
        'charts.example.com/nextcloud-9.0.0.tgz' => Http::response(ChartArchive::make('nextcloud', '9.0.0', $defaults)),
        'charts.example.com/nextcloud-9.0.1.tgz' => Http::response(ChartArchive::make('nextcloud', '9.0.1', $defaults)),
        // 9.1.0 renamed image.tag, which Kumulicp sets
        'charts.example.com/nextcloud-9.1.0.tgz' => Http::response(ChartArchive::make('nextcloud', '9.1.0', ['image' => ['repository' => '', 'version' => ''], 'replicaCount' => 1])),
    ]);

    $result = checkerWith(['image' => ['repository' => 'nextcloud', 'tag' => '33.0.1'], 'replicaCount' => 1])->check($version);

    expect($result['image'])->toMatchArray(['current' => '33.0.1', 'latest' => '33.0.2', 'error' => null])
        ->and($result['chart']['latest'])->toBe('9.1.0')
        ->and($result['chart']['latest_compatible'])->toBe('9.0.1')
        ->and($result['chart']['compatibility']['status'])->toBe('incompatible')
        ->and(implode(' ', $result['chart']['compatibility']['issues']))->toContain('image.tag');

    $version->refresh();
    expect($version->newerImageTag())->toBe('33.0.2')
        ->and($version->newerChartVersion())->toBe('9.0.1')
        ->and($version->incompatibleChart()['version'])->toBe('9.1.0');
});

it('lists image tags from the version\'s image registry and its credentials', function () {
    $registry = RepoSecret::factory()->create(['registry' => 'registry.example.com', 'username' => 'puller', 'password' => 'secret']);
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'name' => '1.0.0',
        'image_registry_id' => $registry->id,
        'settings' => ['image_repo_name' => 'org/app'],
    ]);

    Http::fake(['registry.example.com/v2/org/app/tags/list*' => Http::sequence()
        ->push('', 401, ['WWW-Authenticate' => 'Basic realm="registry"'])
        ->push(['tags' => ['1.0.0', '1.0.1']]),
    ]);

    $result = checkerWith(null)->check($version);

    expect($result['image'])->toMatchArray(['latest' => '1.0.1', 'error' => null]);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('puller:secret')));
});

it('blocks a chart whose schema rejects the values Kumulicp sends', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'settings' => ['helm_repo_name' => 'https://charts.example.com', 'chart_name' => 'demo', 'chart_version' => '1.0.0'],
    ]);

    Http::fake([
        'charts.example.com/index.yaml' => Http::response(ChartArchive::index('demo', ['1.0.0', '1.1.0'])),
        'charts.example.com/demo-1.0.0.tgz' => Http::response(ChartArchive::make('demo', '1.0.0', ['replicaCount' => 1])),
        'charts.example.com/demo-1.1.0.tgz' => Http::response(ChartArchive::make('demo', '1.1.0', ['replicaCount' => 1], [], [
            'type' => 'object',
            'properties' => ['replicaCount' => ['type' => 'string']],
        ])),
    ]);

    $result = checkerWith(['replicaCount' => 2])->check($version);

    expect($result['chart']['latest'])->toBe('1.1.0')
        ->and($result['chart']['latest_compatible'])->toBeNull()
        ->and($result['chart']['compatibility']['status'])->toBe('incompatible');
});

it('checks a kubernetes cluster version against the chart kubeVersion', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'settings' => ['helm_repo_name' => 'https://charts.example.com', 'chart_name' => 'demo', 'chart_version' => '1.0.0'],
    ]);
    runOn($this->application, $version, 'kubernetes', ['address' => 'https://cluster.example.com:6443', 'api_secret' => 'token', 'settings' => ['k8s_auth_type' => 'bearer_token']]);

    Http::fake([
        'cluster.example.com:6443/version' => Http::response(['gitVersion' => 'v1.28.5+k3s1']),
        'charts.example.com/index.yaml' => Http::response(ChartArchive::index('demo', ['1.0.0', '1.1.0', '1.2.0'])),
        'charts.example.com/demo-1.0.0.tgz' => Http::response(ChartArchive::make('demo', '1.0.0')),
        'charts.example.com/demo-1.1.0.tgz' => Http::response(ChartArchive::make('demo', '1.1.0', [], ['kubeVersion' => '>=1.25.0-0'])),
        'charts.example.com/demo-1.2.0.tgz' => Http::response(ChartArchive::make('demo', '1.2.0', [], ['kubeVersion' => '>=1.30.0-0'])),
    ]);

    $result = checkerWith([])->check($version);

    expect($result['chart']['latest'])->toBe('1.2.0')
        ->and($result['chart']['latest_compatible'])->toBe('1.1.0')
        ->and($result['chart']['compatibility']['targets'][0])->toMatchArray(['interface' => 'kubernetes', 'status' => 'incompatible'])
        ->and($result['chart']['compatibility']['targets'][0]['reason'])->toContain('1.28.5');

    Http::assertSent(fn ($request) => $request->url() === 'https://cluster.example.com:6443/version');
});

it('uses Rancher\'s filtered ClusterRepo index as its compatibility verdict', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'settings' => ['helm_repo_name' => 'kumulicp-charts', 'chart_name' => 'demo', 'chart_version' => '1.0.0'],
    ]);
    runOn($this->application, $version, 'rancher', ['address' => 'https://rancher.example.com']);

    $index = fn (array $versions) => ['entries' => ['demo' => array_map(fn ($v) => ['version' => $v], $versions)]];

    Http::fake(function ($request) use ($index) {
        $url = $request->url();

        if (str_contains($url, 'link=index') && str_contains($url, 'skipFilter=true')) {
            return Http::response($index(['1.0.0', '1.1.0', '2.0.0']));
        }
        if (str_contains($url, 'link=index')) {
            // Rancher hides 2.0.0 on this cluster
            return Http::response($index(['1.0.0', '1.1.0']));
        }
        if (str_contains($url, 'link=chart')) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);

            return Http::response(ChartArchive::make('demo', $query['version'], [], $query['version'] === '2.0.0' ? ['annotations' => ['catalog.cattle.io/rancher-version' => '>= 2.12.0-0']] : []));
        }

        return Http::response('', 404);
    });

    $result = checkerWith([])->check($version);

    expect($result['chart']['latest'])->toBe('2.0.0')
        ->and($result['chart']['latest_compatible'])->toBe('1.1.0')
        ->and($result['chart']['compatibility']['targets'][0])->toMatchArray(['interface' => 'rancher', 'status' => 'incompatible'])
        ->and($result['chart']['compatibility']['targets'][0]['reason'])->toContain('>= 2.12.0-0');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://rancher.example.com/v1/catalog.cattle.io.clusterrepos/kumulicp-charts')
        && $request->hasHeader('Authorization'));
});

it('reports a ClusterRepo chart as not checkable when no Rancher server runs the app', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'settings' => ['helm_repo_name' => 'kumulicp-charts', 'chart_name' => 'demo', 'chart_version' => '1.0.0'],
    ]);

    Http::fake();

    $result = checkerWith(null)->check($version);

    expect($result['chart']['error'])->toContain('kumulicp-charts')
        ->and($result['image'])->toBeNull();
    Http::assertNothingSent();
});

it('records a registry failure instead of throwing', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'settings' => ['image_repo_name' => 'org/app', 'image_registry' => 'ghcr.io'],
    ]);

    Http::fake(['ghcr.io/*' => Http::response('', 500)]);

    $result = checkerWith(null)->check($version);

    expect($result['image']['latest'])->toBeNull()
        ->and($result['image']['error'])->not->toBeNull()
        ->and($result['chart'])->toBeNull();
});

// ─── Images besides the primary one ──────────────────────────────────────────

function versionWithImage(Application $application, array $image, array $settings = [], string $name = '33.0.1'): AppVersion
{
    $version = AppVersion::factory()->create(['application_id' => $application->id, 'name' => $name, 'settings' => $settings ?: null]);

    AppVersionImage::factory()->create($image + ['app_version_id' => $version->id, 'registry' => 'registry.example.com', 'tag' => '1.0.0', 'repository' => 'org/other']);

    return $version;
}

it('checks every image of a version and keeps the primary one as `image`', function () {
    $version = versionWithImage($this->application, [
        'key' => 'imaginary',
        'registry' => 'docker.io',
        'repository' => 'nextcloud/aio-imaginary',
        'tag' => '20261109_101109',
        'tag_pattern' => '^\d{8}_\d{6}$',
    ], ['image_registry' => 'docker.io', 'image_repo_name' => 'library/nextcloud']);

    Http::fake([
        'registry-1.docker.io/v2/library/nextcloud/tags/list*' => Http::response(['tags' => ['33.0.1', '33.0.2']]),
        // Only the dated tags are candidates for imaginary
        'registry-1.docker.io/v2/nextcloud/aio-imaginary/tags/list*' => Http::response(['tags' => ['20261109_101109', '20270101_000000', '20261201_090000', 'latest', '20270201_1']]),
    ]);

    $result = checkerWith(null)->check($version);

    expect($result['images'])->toHaveKeys(['primary', 'imaginary'])
        ->and($result['images']['primary'])->toMatchArray(['current' => '33.0.1', 'latest' => '33.0.2', 'policy' => 'track', 'error' => null])
        ->and($result['images']['imaginary'])->toMatchArray(['current' => '20261109_101109', 'latest' => '20270101_000000', 'error' => null])
        ->and($result['image'])->toBe($result['images']['primary']);

    expect($version->refresh()->newerImageTag())->toBe('33.0.2')
        ->and($version->update_check['images']['imaginary']['latest'])->toBe('20270101_000000');
});

it('has no `image` for a version without a primary image', function () {
    $version = versionWithImage($this->application, ['key' => 'redis']);

    Http::fake(['registry.example.com/v2/org/other/tags/list*' => Http::response(['tags' => ['1.0.0', '1.0.1']])]);

    $result = checkerWith(null)->check($version);

    expect($result['image'])->toBeNull()
        ->and(array_keys($result['images']))->toBe(['redis'])
        ->and($result['images']['redis']['latest'])->toBe('1.0.1');
});

it('does not ask the registry about pinned or ignored images', function () {
    $version = versionWithImage($this->application, ['key' => 'redis', 'update_policy' => 'pinned']);
    AppVersionImage::factory()->create(['app_version_id' => $version->id, 'key' => 'proxy', 'update_policy' => 'ignore', 'tag' => '2.0.0']);

    Http::fake();

    $result = checkerWith(null)->check($version);

    expect($result['images']['redis'])->toMatchArray(['current' => '1.0.0', 'latest' => null, 'policy' => 'pinned', 'error' => null])
        ->and($result['images']['proxy'])->toMatchArray(['latest' => null, 'policy' => 'ignore']);

    Http::assertNothingSent();
});

it('offers only what an image\'s update policy allows', function () {
    $version = versionWithImage($this->application, ['key' => 'redis', 'tag' => '1.2.3', 'update_policy' => 'patch']);

    Http::fake(['registry.example.com/v2/org/other/tags/list*' => Http::response(['tags' => ['1.2.3', '1.2.9', '1.3.0', '2.0.0']])]);

    expect(checkerWith(null)->check($version)['images']['redis']['latest'])->toBe('1.2.9');
});

it('says which image could not be looked up and still checks the others', function () {
    $version = versionWithImage($this->application, ['key' => 'redis'], ['image_registry' => 'docker.io', 'image_repo_name' => 'library/nextcloud']);

    Http::fake([
        'registry-1.docker.io/v2/library/nextcloud/tags/list*' => Http::response(['tags' => ['33.0.1', '33.0.2']]),
        'registry.example.com/*' => Http::response('', 500),
    ]);

    $result = checkerWith(null)->check($version);

    expect($result['images']['redis']['latest'])->toBeNull()
        ->and($result['images']['redis']['error'])->not->toBeEmpty()
        ->and($result['images']['primary'])->toMatchArray(['latest' => '33.0.2', 'error' => null]);

    $version->refresh();

    expect($version->imageCheckErrors())->toHaveCount(1)
        ->and($version->imageCheckErrors()[0]['key'])->toBe('redis')
        ->and($version->updateSummary()['images_errors'][0]['error'])->not->toBeEmpty();
});

it('reports a tag pattern that is not a regular expression', function () {
    $version = versionWithImage($this->application, ['key' => 'redis', 'tag_pattern' => '(']);

    Http::fake(['registry.example.com/v2/org/other/tags/list*' => Http::response(['tags' => ['1.0.0', '1.0.1']])]);

    $image = checkerWith(null)->check($version)['images']['redis'];

    expect($image['latest'])->toBeNull()
        ->and($image['error'])->toContain('tag pattern');
});

it('offers an image kept by digest with the digest of its new tag', function () {
    $version = versionWithImage($this->application, ['key' => 'synapse', 'digest' => 'sha256:old']);

    Http::fake([
        'registry.example.com/v2/org/other/tags/list*' => Http::response(['tags' => ['1.0.0', '1.1.0']]),
        'registry.example.com/v2/org/other/manifests/1.1.0' => Http::response('{}', 200, ['Docker-Content-Digest' => 'sha256:new']),
    ]);

    $image = checkerWith(null)->check($version)['images']['synapse'];

    expect($image)->toMatchArray(['latest' => '1.1.0', 'digest' => 'sha256:new', 'error' => null]);
});

it('does not offer an image kept by digest when the digest of its new tag is not known', function () {
    $version = versionWithImage($this->application, ['key' => 'synapse', 'digest' => 'sha256:old']);

    Http::fake([
        'registry.example.com/v2/org/other/tags/list*' => Http::response(['tags' => ['1.0.0', '1.1.0']]),
        'registry.example.com/v2/org/other/manifests/1.1.0' => Http::response('{}'),
    ]);

    $image = checkerWith(null)->check($version)['images']['synapse'];

    expect($image['latest'])->toBeNull()
        ->and($image)->not->toHaveKey('digest')
        ->and($image['error'])->toContain('1.1.0');
});

it('asks about a repository once however many versions use it', function () {
    $first = versionWithImage($this->application, ['key' => 'redis'], [], '1.0');
    $second = versionWithImage($this->application, ['key' => 'redis'], [], '2.0');

    Http::fake(['registry.example.com/v2/org/other/tags/list*' => Http::response(['tags' => ['1.0.0', '1.0.1']])]);

    $checker = checkerWith(null);

    expect($checker->check($first)['images']['redis']['latest'])->toBe('1.0.1')
        ->and($checker->check($second)['images']['redis']['latest'])->toBe('1.0.1');

    Http::assertSentCount(1);
});

it('waits for a registry that is down only once', function () {
    $first = versionWithImage($this->application, ['key' => 'redis'], [], '1.0');
    $second = versionWithImage($this->application, ['key' => 'redis'], [], '2.0');

    Http::fake(['registry.example.com/*' => Http::response('', 503)]);

    $checker = checkerWith(null);

    expect($checker->check($first)['images']['redis']['error'])->not->toBeEmpty()
        ->and($checker->check($second)['images']['redis']['error'])->not->toBeEmpty();

    Http::assertSentCount(1);
});

it('follows the version\'s settings when they were changed without the version noticing', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'name' => '1.0.0',
        'settings' => ['image_registry' => 'registry.example.com', 'image_repo_name' => 'org/old'],
    ]);

    // A bulk update skips the version's own events
    AppVersion::whereKey($version->id)->update(['settings' => json_encode(['image_registry' => 'registry.example.com', 'image_repo_name' => 'org/new'])]);

    Http::fake(['registry.example.com/v2/org/new/tags/list*' => Http::response(['tags' => ['1.0.0', '1.0.1']])]);

    $result = checkerWith(null)->check($version->fresh());

    expect($result['image']['latest'])->toBe('1.0.1');
});
