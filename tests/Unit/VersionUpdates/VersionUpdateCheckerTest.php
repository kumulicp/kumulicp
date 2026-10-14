<?php

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\OrgServer;
use App\Server;
use App\Support\VersionUpdates\ChartCompatibilityChecker;
use App\Support\VersionUpdates\ValuesContract;
use App\Support\VersionUpdates\VersionUpdateChecker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

it('checks a helm_k8s cluster version against the chart kubeVersion', function () {
    $version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'settings' => ['helm_repo_name' => 'https://charts.example.com', 'chart_name' => 'demo', 'chart_version' => '1.0.0'],
    ]);
    runOn($this->application, $version, 'helm_k8s', ['address' => 'https://cluster.example.com:6443', 'api_secret' => 'token', 'settings' => ['k8s_auth_type' => 'bearer_token']]);

    Process::fake([
        '*' => Process::result(json_encode(['serverVersion' => ['gitVersion' => 'v1.28.5+k3s1']])),
    ]);
    Http::fake([
        'charts.example.com/index.yaml' => Http::response(ChartArchive::index('demo', ['1.0.0', '1.1.0', '1.2.0'])),
        'charts.example.com/demo-1.0.0.tgz' => Http::response(ChartArchive::make('demo', '1.0.0')),
        'charts.example.com/demo-1.1.0.tgz' => Http::response(ChartArchive::make('demo', '1.1.0', [], ['kubeVersion' => '>=1.25.0-0'])),
        'charts.example.com/demo-1.2.0.tgz' => Http::response(ChartArchive::make('demo', '1.2.0', [], ['kubeVersion' => '>=1.30.0-0'])),
    ]);

    $result = checkerWith([])->check($version);

    expect($result['chart']['latest'])->toBe('1.2.0')
        ->and($result['chart']['latest_compatible'])->toBe('1.1.0')
        ->and($result['chart']['compatibility']['targets'][0])->toMatchArray(['interface' => 'helm_k8s', 'status' => 'incompatible'])
        ->and($result['chart']['compatibility']['targets'][0]['reason'])->toContain('1.28.5');

    Process::assertRan(fn ($process) => in_array('version', $process->command));
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
