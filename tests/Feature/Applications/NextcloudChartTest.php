<?php

use App\AppPlan;
use App\AppVersion;
use App\AppVersionImage;
use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Organization;
use App\OrgServer;
use App\RepoSecret;
use App\Server;
use App\Support\Facades\Application;

it('gives the app its own database password rather than the organization secret', function () {
    $organization = Organization::factory()->create(['secretpw' => 'organization-secret']);

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

    $database_server = Server::factory()->create([
        'name' => 'shared-db',
        'type' => 'database',
        'interface' => 'app_database',
        'host' => 'shared-db.db.internal',
        'internal_address' => 'shared-db.db.svc',
        'default_web_server' => 0,
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => [],
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();

    $org_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => $database_server->id]);
    $org_server->backup_server_id = $org_server->id;
    $org_server->save();

    // The chart's environment reads the web server's address
    $web_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => Server::factory()->create()->id]);

    $app_instance->web_server_id = $web_server->id;
    $app_instance->db_password = 'instance-database-secret';
    $app_instance->database_server_id = $org_server->id;
    $app_instance->databasename = 'demo_nextcloud';
    $app_instance->save();

    $values = (new NextcloudChart($organization, $app_instance->fresh()))->values();

    expect($values['externalDatabase']['enabled'])->toBeTrue();
    expect($values['externalDatabase']['host'])->toBe('shared-db.db.svc');
    expect($values['externalDatabase']['user'])->toBe('demo_nextcloud');
    expect($values['externalDatabase']['password'])->toBe('instance-database-secret');
});

// The chart for a new Nextcloud instance. Images are given to the version before it is activated.
function nextcloudChart(array $configurations = [], array $images = [], array $additional_configs = []): NextcloudChart
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

    foreach ($images as $image) {
        AppVersionImage::factory()->create($image + ['app_version_id' => $version->id]);
    }

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => $configurations,
            'additionalConfigs' => $additional_configs,
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();

    // The chart's environment reads the web server's address
    $web_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => Server::factory()->create()->id]);
    $app_instance->web_server_id = $web_server->id;
    $app_instance->save();

    return new NextcloudChart($organization, $app_instance->fresh());
}

function nextcloudChartValues(array $configurations = []): array
{
    return nextcloudChart($configurations)->values();
}

it('leaves the chart security contexts and port 80 alone, so it matches the official chart', function () {
    $values = nextcloudChartValues();

    expect($values['nextcloud'])->not->toHaveKeys(['containerPort', 'securityContext', 'podSecurityContext'])
        ->and($values['nextcloud']['configs'])->not->toHaveKey('restricted.config.php')
        ->and($values['cronjob'])->not->toHaveKey('type')
        ->and(collect($values['nextcloud']['extraEnv'])->pluck('name'))->not->toContain('APACHE_PORT');
});

it('does not know about restricted security contexts; that is an extension', function () {
    // Even with the setting on, only a module that registers it changes the chart
    $values = nextcloudChartValues(['restricted-security-context' => true, 'cronjob' => true]);

    expect($values['nextcloud'])->not->toHaveKeys(['containerPort', 'securityContext', 'podSecurityContext'])
        ->and($values['cronjob'])->not->toHaveKey('type');
});

it('runs imaginary from the maintained aio image with an exact tag', function () {
    $image = nextcloudChartValues(['imaginary-enabled' => true])['imaginary']['image'];

    expect($image)->toMatchArray(['registry' => 'docker.io', 'repository' => 'nextcloud/aio-imaginary'])
        ->and($image['tag'])->not->toBeEmpty()->not->toBe('latest');
});

it('sets resources where the chart reads them, so every pod has requests and a memory limit', function () {
    $values = nextcloudChartValues(['cronjob' => true, 'imaginary-enabled' => true, 'metrics-enabled' => true]);

    foreach ([
        $values['resources'],
        $values['cronjob']['sidecar']['resources'],
        $values['cronjob']['cronjob']['resources'],
        $values['imaginary']['resources'],
        $values['metrics']['resources'],
    ] as $resources) {
        expect($resources['requests'])->toHaveKeys(['cpu', 'memory'])
            ->and($resources['limits'])->toHaveKey('memory');
    }
});

// ─── Images of the version ───────────────────────────────────────────────────

function imaginaryImage(array $attributes = []): array
{
    return $attributes + [
        'key' => 'imaginary',
        'registry' => 'ghcr.io',
        'repository' => 'nextcloud/aio-imaginary',
        'tag' => '20270101_000000',
        'values_path' => 'imaginary.image',
    ];
}

it('runs imaginary from the image the version has for it', function () {
    $values = nextcloudChart(['imaginary-enabled' => true], [imaginaryImage()])->valuesWithAdditionalConfigs();

    expect($values['imaginary']['image'])->toMatchArray([
        'registry' => 'ghcr.io',
        'repository' => 'nextcloud/aio-imaginary',
        'tag' => '20270101_000000',
    ]);
});

it('does not let a setting of the plan for the same place in the values put back another image', function () {
    // A setting added to the plan is written to the values by its name: imaginary-image-tag is imaginary.image.tag
    $values = nextcloudChart(
        ['imaginary-enabled' => true, 'imaginary-image-tag' => 'from-the-plan'],
        [imaginaryImage()],
        ['imaginary-image-tag' => ['name' => 'imaginary-image-tag']]
    )->valuesWithAdditionalConfigs();

    expect($values['imaginary']['image']['tag'])->toBe('20270101_000000');
});

it('runs the image the profile recommends for a version without its own imaginary image', function () {
    $values = nextcloudChart(['imaginary-enabled' => true])->valuesWithAdditionalConfigs();

    expect($values['imaginary']['image'])->toMatchArray(['registry' => 'docker.io', 'repository' => 'nextcloud/aio-imaginary'])
        ->and($values['imaginary']['image']['tag'])->not->toBeEmpty()->not->toBe('latest');
});

it('no longer has configurations for the imaginary image', function () {
    $profile = new App\Integrations\Applications\Nextcloud\NextcloudProfile;

    foreach (['imaginary-image-registry', 'imaginary-image-repository', 'imaginary-image-tag'] as $name) {
        expect($profile->configuration($name))->toBeNull();
    }
});

it('writes other images beside what the chart keeps under their path', function () {
    $values = nextcloudChart(['metrics-enabled' => true], [[
        'key' => 'exporter',
        'registry' => null,
        'repository' => 'xperimental/nextcloud-exporter',
        'tag' => '0.8.0',
        'digest' => 'sha256:abc123',
        'values_path' => 'metrics.image',
    ]])->valuesWithAdditionalConfigs();

    expect($values['metrics']['image'])->toBe([
        'repository' => 'xperimental/nextcloud-exporter',
        'tag' => '0.8.0',
        'digest' => 'sha256:abc123',
    ])->and($values['metrics']['resources'])->not->toBeEmpty();
});

it('leaves the primary image to the chart and does not write images the chart ships or jobs run', function () {
    $values = nextcloudChart([], [
        imaginaryImage(['key' => 'shipped', 'source' => 'chart', 'values_path' => 'shipped.image']),
        imaginaryImage(['key' => 'migrate', 'role' => 'job', 'values_path' => 'migrate.image']),
    ])->valuesWithAdditionalConfigs();

    expect($values)->not->toHaveKeys(['shipped', 'migrate'])
        ->and($values['image'])->toMatchArray(['repository' => 'library/nextcloud', 'tag' => '1.0']);
});

it('pulls every image with the registry login it needs, once each', function () {
    $first = RepoSecret::factory()->create(['registry' => 'ghcr.io', 'username' => 'bot', 'password' => 'pw']);
    $second = RepoSecret::factory()->create(['registry' => 'quay.io', 'username' => 'bot', 'password' => 'pw']);
    $anonymous = RepoSecret::factory()->create(['registry' => 'docker.io']);

    $chart = nextcloudChart([], [
        imaginaryImage(['key' => 'one', 'registry_secret_id' => $first->id, 'position' => 1]),
        imaginaryImage(['key' => 'two', 'registry_secret_id' => $second->id, 'position' => 2]),
        imaginaryImage(['key' => 'three', 'registry_secret_id' => $first->id, 'position' => 3]),
        imaginaryImage(['key' => 'four', 'registry_secret_id' => $anonymous->id, 'position' => 4]),
    ]);

    expect($chart->imagePullSecrets())->toBe([$first->k8sSecretName(), $second->k8sSecretName()]);
});
