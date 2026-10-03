<?php

use App\Organization;
use App\OrgServer;
use App\SecurityFinding;
use App\SecurityScan;
use App\Server;
use App\Support\Security\PodSecurityPreflight;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makePreflightOrgServer(string $interface = 'helm_k8s'): OrgServer
{
    $server = Server::factory()->create([
        'interface' => $interface,
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    return OrgServer::create(['organization_id' => Organization::factory()->create()->id, 'server_id' => $server->id]);
}

function pssWarning(string $text): string
{
    return '299 - "'.str_replace('"', '\\"', $text).'"';
}

function violationHeaders(): array
{
    return ['Warning' => [
        pssWarning('existing pods in namespace "x" violate the new PodSecurity enforce level "baseline:latest"'),
        pssWarning('nextcloud-5d9f (and 1 other pod): hostPath volumes (volume "h"), privileged (container "c" must not set securityContext.privileged=true)'),
    ]];
}

it('dry-runs the enforce label and reports no violations when the server returns no warnings', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response(['metadata' => []])]);

    $result = PodSecurityPreflight::check($org_server->organization, $org_server, 'restricted');

    expect($result)->toMatchArray(['status' => 'ok', 'pod_count' => 0, 'pods' => []]);
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/api/v1/namespaces/'.$org_server->organization->slug.'?dryRun=All')
        && json_decode($request->body(), true) === ['metadata' => ['labels' => ['pod-security.kubernetes.io/enforce' => 'restricted']]]);
});

it('pins the version when one is given', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response([])]);

    PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline', 'v1.30');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['metadata']['labels'] === [
        'pod-security.kubernetes.io/enforce' => 'baseline',
        'pod-security.kubernetes.io/enforce-version' => 'v1.30',
    ]);
});

it('lists the pods that would be blocked', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response([], 200, violationHeaders())]);

    $result = PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline');

    expect($result['status'])->toBe('violations')
        ->and($result['pod_count'])->toBe(2)
        ->and($result['pods'][0])->toMatchArray(['pod' => 'nextcloud-5d9f', 'others' => 1])
        ->and($result['pods'][0]['violations'])->toHaveCount(2);
});

it('treats a namespace that does not exist yet as having nothing to break', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

    expect(PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline')['status'])->toBe('ok');
});

it('reports a failed check as an error, not as safe', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response(['message' => 'namespaces "x" is forbidden'], 403)]);

    $result = PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline');

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toContain('forbidden');
});

it('does not guess for Rancher servers', function () {
    $org_server = makePreflightOrgServer('rancher');
    Http::fake();

    expect(PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline')['status'])->toBe('unsupported');
    Http::assertNothingSent();
});

it('rejects an unknown level', function () {
    $org_server = makePreflightOrgServer();

    PodSecurityPreflight::check($org_server->organization, $org_server, 'strict');
})->throws(InvalidArgumentException::class);

it('keeps a result as a security scan with one finding per pod group', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response([], 200, violationHeaders())]);

    $result = PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline');
    $scan = PodSecurityPreflight::record($org_server, $result);

    expect($scan->tool)->toBe('pod-security')
        ->and($scan->status)->toBe('complete')
        ->and($scan->triggered_by)->toBe('preflight')
        ->and($scan->summary['high'])->toBe(1);

    $finding = SecurityFinding::where('security_scan_id', $scan->id)->first();
    expect($finding->resource_name)->toBe('nextcloud-5d9f')
        ->and($finding->rule_id)->toBe('psa.enforce.baseline')
        ->and($finding->metadata['violations'])->toHaveCount(2);
});

it('records a failed check as a failed scan, and nothing for unsupported servers', function () {
    $org_server = makePreflightOrgServer();
    Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

    $scan = PodSecurityPreflight::record($org_server, PodSecurityPreflight::check($org_server->organization, $org_server, 'baseline'));

    expect($scan->status)->toBe('failed')
        ->and($scan->error_message)->toContain('forbidden');

    $rancher = makePreflightOrgServer('rancher');
    expect(PodSecurityPreflight::record($rancher, PodSecurityPreflight::check($rancher->organization, $rancher, 'baseline')))->toBeNull()
        ->and(SecurityScan::count())->toBe(1);
});
