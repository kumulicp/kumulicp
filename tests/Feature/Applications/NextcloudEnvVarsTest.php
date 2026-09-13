<?php

use App\AppInstance;
use App\AppPlan;
use App\AppVersion;
use App\Application;
use App\Integrations\Applications\Nextcloud\NextcloudEnvVars;
use App\OrgServer;
use App\Organization;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;

function makeNextcloudAppInstance(Organization $organization, Application $app, AppVersion $version, AppPlan $plan, ?AppInstance $parent = null): AppInstance
{
    $server = Server::factory()->create();
    $org_server = OrgServer::create([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
    ]);

    return AppInstance::factory()->create([
        'organization_id' => $organization->id,
        'application_id' => $app->id,
        'version_id' => $version->id,
        'plan_id' => $plan->id,
        'web_server_id' => $org_server->id,
        'parent_id' => $parent?->id,
    ]);
}

it('binds a shared Nextcloud hub with the read-only directory reader, never the rootDN', function () {
    config()->set('ldap.connections.directory_reader.username', 'cn=directory-reader,o=server,dc=test,dc=local');
    config()->set('ldap.connections.directory_reader.password', 'reader-secret');
    config()->set('ldap.connections.default.username', 'cn=admin,dc=test,dc=local');
    config()->set('ldap.connections.default.password', 'root-secret');

    $app = ApplicationFacade::initialize('nextcloud');
    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $plan = AppPlan::factory()->create(['application_id' => $app->id]);

    $hub_org = Organization::factory()->create();
    $hub = makeNextcloudAppInstance($hub_org, $app, $version, $plan);

    $child_org = Organization::factory()->create();
    makeNextcloudAppInstance($child_org, $app, $version, $plan, $hub);

    $env = (new NextcloudEnvVars)->get($hub);

    expect($env['LDAP_ADMIN'])->toBe('cn=directory-reader,o=server,dc=test,dc=local');
    expect($env['LDAP_AGENT_PASSWORD'])->toBe('reader-secret');
    expect($env['LDAP_ADMIN'])->not->toBe(config('ldap.connections.default.username'));
    expect($env['LDAP_AGENT_PASSWORD'])->not->toBe(config('ldap.connections.default.password'));
});

it('binds a standalone Nextcloud instance with its own org admin', function () {
    $app = ApplicationFacade::initialize('nextcloud');
    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $plan = AppPlan::factory()->create(['application_id' => $app->id]);

    $organization = Organization::factory()->create();
    $instance = makeNextcloudAppInstance($organization, $app, $version, $plan);

    $env = (new NextcloudEnvVars)->get($instance);

    expect($env['LDAP_ADMIN'])->not->toBe(config('ldap.connections.directory_reader.username'));
    expect($env['LDAP_ADMIN'])->not->toBe(config('ldap.connections.default.username'));
});
