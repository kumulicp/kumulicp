<?php

use App\AppPlan;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\NextcloudChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\Crypt;

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

    $app_instance->updateSetting('db_password', Crypt::encryptString('instance-database-secret'));
    $app_instance->database_server_id = $org_server->id;
    $app_instance->databasename = 'demo_nextcloud';
    $app_instance->save();

    $values = (new NextcloudChart($organization, $app_instance->fresh()))->values();

    expect($values['externalDatabase']['enabled'])->toBeTrue();
    expect($values['externalDatabase']['host'])->toBe('shared-db.db.svc');
    expect($values['externalDatabase']['user'])->toBe('demo_nextcloud');
    expect($values['externalDatabase']['password'])->toBe('instance-database-secret');
});
