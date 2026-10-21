<?php

use App\Organization;
use App\OrgServer;
use App\Plan;
use App\SecurityScan;
use App\Server;
use App\Support\Security\SecurityTier;
use App\Support\Security\TierPreflightGate;
use Illuminate\Support\Facades\Http;

function gateOrganization(?string $mode = 'managed', string $interface = 'helm_k8s', ?string $tier = null): Organization
{
    $server = Server::factory()->create([
        'interface' => $interface,
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => array_filter(['k8s_auth_type' => 'bearer_token', 'security_mode' => $mode]),
    ]);
    $plan = Plan::factory()->create(['settings' => $tier ? ['security' => ['tier' => $tier]] : []]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);
    OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);

    return $organization->load('servers.server');
}

function gateViolationHeaders(): array
{
    return ['Warning' => [
        '299 - "existing pods in namespace \"x\" violate the new PodSecurity enforce level \"baseline:latest\""',
        '299 - "web-1: privileged (container \"c\" must not set securityContext.privileged=true)"',
    ]];
}

it('has nothing to check when the tier does not enforce', function () {
    Http::fake();

    expect(TierPreflightGate::blockers([gateOrganization()], SecurityTier::presets()['observe']))->toBe([]);
    Http::assertNothingSent();
});

it('blocks when existing workloads would violate the new enforce level', function () {
    Http::fake(['*' => Http::response([], 200, gateViolationHeaders())]);
    $organization = gateOrganization();

    $blockers = TierPreflightGate::blockers([$organization], SecurityTier::presets()['baseline']);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toMatchArray(['organization' => $organization->slug, 'status' => 'violations', 'pods' => 1])
        ->and(SecurityScan::where('tool', 'pod-security')->count())->toBe(1);
});

it('lets a clean namespace through', function () {
    Http::fake(['*' => Http::response([])]);

    expect(TierPreflightGate::blockers([gateOrganization()], SecurityTier::presets()['baseline']))->toBe([]);
});

it('treats a check that could not run as a blocker', function () {
    Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

    $blockers = TierPreflightGate::blockers([gateOrganization()], SecurityTier::presets()['baseline']);

    expect($blockers[0]['status'])->toBe('error')
        ->and($blockers[0]['detail'])->toContain('forbidden');
});

it('only checks namespaces KumuliCP would relabel', function () {
    Http::fake(['*' => Http::response([], 200, gateViolationHeaders())]);

    $skipped = [gateOrganization(null), gateOrganization('observe'), gateOrganization('managed', 'rancher')];

    expect(TierPreflightGate::blockers($skipped, SecurityTier::presets()['baseline']))->toBe([]);
    Http::assertNothingSent();
});

it('asks for the batch command instead of checking an unbounded number of namespaces', function () {
    Http::fake(['*' => Http::response([])]);
    $organizations = array_map(fn () => gateOrganization(), range(1, TierPreflightGate::MAX_NAMESPACES + 1));

    $blockers = TierPreflightGate::blockers($organizations, SecurityTier::presets()['baseline']);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]['status'])->toBe('too_many')
        ->and($blockers[0]['detail'])->toContain('servers:preflight-pod-security baseline');
});

it('finds the organizations on plans that use a tier', function () {
    $on = gateOrganization('managed', 'helm_k8s', 'mine');
    gateOrganization('managed', 'helm_k8s', 'other');

    expect(TierPreflightGate::organizationsOnTier('mine')->pluck('id')->all())->toBe([$on->id]);
});

it('summarises blockers readably, capped at five', function () {
    $blockers = array_merge(
        [['organization' => 'acme', 'server' => 's', 'status' => 'violations', 'pods' => 3, 'detail' => '']],
        [['organization' => 'demo', 'server' => 's', 'status' => 'error', 'pods' => 0, 'detail' => 'forbidden']],
        array_map(fn ($n) => ['organization' => "org{$n}", 'server' => 's', 'status' => 'violations', 'pods' => 1, 'detail' => ''], range(1, 5)),
    );

    expect(TierPreflightGate::summary($blockers))
        ->toBe("acme (3 pods), demo (couldn't check: forbidden), org1 (1 pod), org2 (1 pod), org3 (1 pod) and 2 more");
});
