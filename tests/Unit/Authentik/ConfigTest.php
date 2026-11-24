<?php

// The Authentik server test checks the LDAP source the control panel's users and groups
// come from before it creates anything, so a source that was never made by hand fails
// at once and says why.

use App\Integrations\SSO\Authentik\AuthentikConfigTest;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function authentikServer(array $settings = []): Server
{
    return Server::factory()->create([
        'type' => 'sso',
        'interface' => 'authentik',
        'address' => 'https://auth.example.com',
        'api_key' => 'Bearer',
        'api_secret' => 'token',
        'settings' => $settings,
    ]);
}

function ldapSource(array $overrides = []): array
{
    return $overrides + ['slug' => 'ldap', 'enabled' => true, 'sync_groups' => true, 'group_property_mappings' => ['mapping-1']];
}

/**
 * Authentik as it answers when everything is in place, with the source as given.
 */
function fakeAuthentik(array|int $source): void
{
    Http::fake([
        'auth.example.com/api/v3/sources/ldap/*' => is_int($source) ? Http::response([], $source) : Http::response($source),
        'auth.example.com/api/v3/providers/oauth2/*' => Http::response(['pk' => 1], 201),
        'auth.example.com/api/v3/core/applications/*' => Http::response(['pk' => 'app-1', 'slug' => 'test-app'], 201),
        'auth.example.com/api/v3/core/groups/*' => Http::response(['pk' => 'group-1'], 201),
        'auth.example.com/api/v3/policies/bindings/*' => Http::response(['pk' => 'binding-1'], 201),
    ]);
}

it('passes when the LDAP source is in place, and cleans up what it created', function () {
    fakeAuthentik(ldapSource());

    $result = (new AuthentikConfigTest(authentikServer()))->run();

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/providers/oauth2/1/'));
});

it('reads the source named in the server settings', function () {
    fakeAuthentik(ldapSource(['slug' => 'accounts']));

    (new AuthentikConfigTest(authentikServer(['ldap_source_slug' => 'accounts'])))->run();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_ends_with($request->url(), '/api/v3/sources/ldap/accounts/'));
});

it('fails before creating anything when there is no LDAP source', function () {
    fakeAuthentik(404);

    $result = (new AuthentikConfigTest(authentikServer()))->run();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('"ldap"');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('fails when the LDAP source is turned off, does not sync groups or has no group mapping', function (array $source, string $key) {
    fakeAuthentik(ldapSource($source));

    $result = (new AuthentikConfigTest(authentikServer()))->run();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain(__("admin.servers.authentik.{$key}", ['slug' => 'ldap']));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([
    'turned off' => [['enabled' => false], 'ldap_source_disabled'],
    'no groups' => [['sync_groups' => false], 'ldap_source_no_groups'],
    'no group mapping' => [['group_property_mappings' => []], 'ldap_source_no_group_mapping'],
]);
