<?php

use App\AppPlan;
use App\AppVersion;
use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Organization;
use App\OrgServer;
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

function nextcloudChartValues(array $configurations = []): array
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
            'configurations' => $configurations,
        ],
    ]);

    $app_instance = Application::activate($organization, $app, $version, $app_plan)->get();

    // The chart's environment reads the web server's address
    $web_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => Server::factory()->create()->id]);
    $app_instance->web_server_id = $web_server->id;
    $app_instance->save();

    return (new NextcloudChart($organization, $app_instance->fresh()))->values();
}

it('leaves the chart security contexts and port 80 alone by default', function () {
    $values = nextcloudChartValues();

    expect($values['nextcloud'])->not->toHaveKeys(['containerPort', 'securityContext', 'podSecurityContext'])
        ->and($values)->not->toHaveKey('cronjob.type')
        ->and(collect($values['nextcloud']['extraEnv'])->pluck('name'))->not->toContain('APACHE_PORT');
});

it('runs the nextcloud pods unprivileged on port 8080 when restricted', function () {
    $values = nextcloudChartValues(['restricted-security-context' => true, 'cronjob' => true]);

    expect($values['nextcloud']['containerPort'])->toBe(8080)
        ->and(collect($values['nextcloud']['extraEnv'])->firstWhere('name', 'APACHE_PORT')['value'])->toBe('8080')
        ->and($values['nextcloud']['podSecurityContext'])->toMatchArray(['runAsNonRoot' => true, 'runAsUser' => 33, 'fsGroup' => 33])
        ->and($values['nextcloud']['securityContext'])->toMatchArray(['allowPrivilegeEscalation' => false, 'runAsNonRoot' => true, 'capabilities' => ['drop' => ['ALL']]])
        // crond (the sidecar) needs root, so cron has to be a Kubernetes CronJob
        ->and($values['cronjob']['type'])->toBe('cronjob')
        ->and($values['cronjob']['cronjob']['securityContext']['capabilities'])->toBe(['drop' => ['ALL']])
        ->and($values['imaginary']['securityContext']['allowPrivilegeEscalation'])->toBeFalse()
        ->and($values['metrics']['securityContext']['capabilities'])->toBe(['drop' => ['ALL']]);
});

it('runs imaginary from the maintained aio image with an exact tag', function () {
    $image = nextcloudChartValues(['imaginary-enabled' => true])['imaginary']['image'];

    expect($image)->toMatchArray(['registry' => 'docker.io', 'repository' => 'nextcloud/aio-imaginary'])
        ->and($image['tag'])->not->toBeEmpty()->not->toBe('latest');
});
