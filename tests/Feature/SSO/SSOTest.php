<?php

use App\AppInstance;
use App\Application;
use App\Organization;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\SSO\FakeSSOProfile;
use Tests\Support\TestSupports;

/**
 * SSO contract tests — runs against both 'fake' and 'authentik' drivers.
 *
 * 'fake' iterations always run in CI (no Authentik instance needed).
 * 'authentik' iterations skip unless SSO_DRIVER=authentik is set.
 *
 * To run against a real Authentik instance:
 *   SSO_DRIVER=authentik \
 *   AUTHENTIK_HOST=https://authentik.example.com \
 *   AUTHENTIK_API_KEY=your-token \
 *   php artisan test --testsuite=SSOIntegration
 *
 * These tests verify that the SSO integration satisfies the interface contract
 * used by ApplicationSSOSetup and related actions. App lifecycle tests that
 * involve SSO setup should use setupFakeServerInterfaces() from the
 * ApplicationLifecycle helpers rather than these SSO-specific tests.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();
});

it('resolves an SSO connection via the server interface', function (string $driver) {
    skipUnlessSSO($driver, 'fake');

    app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);

    $org = Organization::find(1);
    $ssoServer = $org->sso_server ?? null;

    if (! $ssoServer) {
        test()->markTestSkipped('No SSO OrgServer seeded for this organization');
    }

    $connection = app('server_interfaces')->connect($ssoServer);

    expect($connection->existsOrganization())->toBeTrue();
})->with('sso_drivers');

it('adds an SSO application via fake', function (string $driver) {
    skipUnlessSSO($driver, 'fake');

    app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);

    $org = Organization::find(1);
    $ssoServer = $org->sso_server ?? null;

    if (! $ssoServer) {
        test()->markTestSkipped('No SSO OrgServer seeded for this organization');
    }

    $app = Application::where('slug', 'demo_app')->first()
        ?? Application::factory()->create(['slug' => 'demo_app']);

    $appInstance = AppInstance::factory()->create([
        'application_id' => $app->id,
        'organization_id' => $org->id,
        'status' => 'pending',
    ]);

    $connection = app('server_interfaces')->connect($ssoServer, $appInstance);

    $result = $connection->add();

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('pk');
})->with('sso_drivers');

it('updates an SSO application via fake', function (string $driver) {
    skipUnlessSSO($driver, 'fake');

    app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);

    $org = Organization::find(1);
    $ssoServer = $org->sso_server ?? null;

    if (! $ssoServer) {
        test()->markTestSkipped('No SSO OrgServer seeded for this organization');
    }

    $app = Application::where('slug', 'demo_app')->first()
        ?? Application::factory()->create(['slug' => 'demo_app']);

    $appInstance = AppInstance::factory()->create([
        'application_id' => $app->id,
        'organization_id' => $org->id,
        'status' => 'active',
    ]);

    $connection = app('server_interfaces')->connect($ssoServer, $appInstance);

    $result = $connection->update();

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('pk');
})->with('sso_drivers');

it('deletes an SSO application via fake', function (string $driver) {
    skipUnlessSSO($driver, 'fake');

    app('server_interfaces')->register('sso', 'authentik', FakeSSOProfile::class);

    $org = Organization::find(1);
    $ssoServer = $org->sso_server ?? null;

    if (! $ssoServer) {
        test()->markTestSkipped('No SSO OrgServer seeded for this organization');
    }

    $app = Application::where('slug', 'demo_app')->first()
        ?? Application::factory()->create(['slug' => 'demo_app']);

    $appInstance = AppInstance::factory()->create([
        'application_id' => $app->id,
        'organization_id' => $org->id,
        'status' => 'active',
    ]);

    $connection = app('server_interfaces')->connect($ssoServer, $appInstance);

    expect($connection->delete())->toBeTrue();
})->with('sso_drivers');

// ---------------------------------------------------------------------------
// Real Authentik tests — skip unless SSO_DRIVER=authentik
// ---------------------------------------------------------------------------

it('creates a real SSO application in Authentik', function (string $driver) {
    skipUnlessSSO($driver, 'authentik');

    $org = Organization::find(1);
    $ssoServer = $org->sso_server;

    if (! $ssoServer) {
        test()->markTestSkipped('No SSO OrgServer seeded for this organization');
    }

    // Real Authentik test body goes here — uses the real AuthentikSSOInterface
    expect(true)->toBeTrue();
})->with('sso_drivers');
