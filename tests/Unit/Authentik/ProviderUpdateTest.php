<?php

// An SSO update has to reach the OAuth2 provider too: providers made before the server sent
// grant types have none, and Authentik 2026.2+ rejects every authorize request for them.

use App\AppInstance;
use App\Integrations\SSO\Authentik\API\Providers;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function providerUpdateSetup(array $serverSettings = [], array $appSettings = []): array
{
    $server = Server::factory()->create([
        'type' => 'sso',
        'interface' => 'authentik',
        'address' => 'https://auth.example.com',
        'api_key' => 'Bearer',
        'api_secret' => 'token',
        'settings' => $serverSettings,
    ]);
    $organization = Organization::factory()->create();
    $org_server = OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);
    // Providers only reads the id, name and settings, so it needn't be saved
    $app_instance = (new AppInstance)->forceFill(['id' => 5, 'name' => 'test-app', 'settings' => $appSettings]);

    return [new Providers($organization, $org_server), $app_instance];
}

it('patches the grant types of the provider stored on the app', function (array $settings, array $expected) {
    Http::fake(['auth.example.com/api/v3/providers/oauth2/*' => Http::response(['pk' => 7])]);
    [$providers, $app_instance] = providerUpdateSetup($settings, ['sso' => ['provider_obj' => ['pk' => 7]]]);

    $providers->update($app_instance);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/api/v3/providers/oauth2/7/')
        && $request->data() === ['grant_types' => $expected]);
})->with([
    'default' => [[], ['authorization_code', 'refresh_token']],
    'from settings' => [['grant_types' => '["authorization_code"]'], ['authorization_code']],
]);

it('finds the provider by name when the app has not stored it', function () {
    Http::fake([
        'auth.example.com/api/v3/providers/oauth2/?*' => Http::response(['results' => [['pk' => 9]]]),
        'auth.example.com/api/v3/providers/oauth2/9/' => Http::response(['pk' => 9]),
    ]);
    [$providers, $app_instance] = providerUpdateSetup();

    $providers->update($app_instance);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/v3/providers/oauth2/9/'));
});

it('does nothing when the provider cannot be found', function () {
    Http::fake(['auth.example.com/api/v3/providers/oauth2/*' => Http::response(['results' => []])]);
    [$providers, $app_instance] = providerUpdateSetup();

    expect($providers->update($app_instance))->toBeNull();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH');
});
