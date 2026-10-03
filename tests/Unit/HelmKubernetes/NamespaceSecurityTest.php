<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\KubernetesNamespace;
use App\Organization;
use App\OrgServer;
use App\Plan;
use App\Server;
use App\Support\Security\NamespaceSecurityReconciler;
use Illuminate\Support\Facades\Process;

function makeSecuredOrgServer(?string $mode, ?string $tier): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => array_filter(['k8s_auth_type' => 'bearer_token', 'security_mode' => $mode]),
    ]);

    $plan = Plan::factory()->create(['settings' => $tier ? ['security' => ['tier' => $tier]] : []]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);

    return OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);
}

function namespaceJson(array $labels = [], array $annotations = []): string
{
    return json_encode(['metadata' => ['labels' => (object) $labels, 'annotations' => (object) $annotations], 'status' => ['phase' => 'Active']]);
}

function ranKubectl(string $verb): Closure
{
    return fn ($process) => is_array($process->command) && in_array($verb, $process->command, true);
}

it('makes no cluster calls at all by default', function () {
    $org_server = makeSecuredOrgServer(null, 'restricted');
    Process::fake();

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unmanaged');
    Process::assertNothingRan();
});

it('does not touch namespaces when the server only observes', function () {
    $org_server = makeSecuredOrgServer('observe', 'restricted');
    Process::fake();

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unmanaged');
    Process::assertNothingRan();
});

it('creates a namespace with exactly the same manifest as before when unmanaged', function () {
    $org_server = makeSecuredOrgServer(null, 'restricted');
    Process::fake(['*' => Process::result('{}')]);

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Process::assertRan(fn ($process) => json_decode($process->input, true) === [
        'apiVersion' => 'v1',
        'kind' => 'Namespace',
        'metadata' => ['name' => $org_server->organization->slug],
    ]);
});

it('creates a namespace with the plan\'s pod security labels when managed', function () {
    $org_server = makeSecuredOrgServer('managed', 'baseline');
    Process::fake(['*' => Process::result('{}')]);

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Process::assertRan(function ($process) {
        $metadata = json_decode($process->input, true)['metadata'] ?? [];

        return ($metadata['labels']['pod-security.kubernetes.io/enforce'] ?? null) === 'baseline'
            && ($metadata['labels']['pod-security.kubernetes.io/warn'] ?? null) === 'restricted'
            && ($metadata['annotations'][NamespaceSecurityReconciler::ANNOTATION] ?? null) === 'baseline';
    });
});

it('creates a namespace with no extra metadata for the none tier even when managed', function () {
    $org_server = makeSecuredOrgServer('managed', null);
    Process::fake(['*' => Process::result('{}')]);

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Process::assertRan(fn ($process) => array_keys(json_decode($process->input, true)['metadata']) === ['name']);
});

it('patches an existing namespace to add the plan\'s labels', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(namespaceJson()))
        ->push(Process::result('{}'))]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result)->toMatchArray(['status' => 'success', 'action' => 'updated']);
    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'patch namespace')
            && str_contains($command, '--type merge')
            && str_contains($command, '"pod-security.kubernetes.io/warn":"restricted"')
            && str_contains($command, NamespaceSecurityReconciler::ANNOTATION);
    });
});

it('does not patch a namespace that already matches', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    $labels = ['pod-security.kubernetes.io/warn' => 'restricted', 'pod-security.kubernetes.io/audit' => 'restricted'];
    Process::fake(['*' => Process::result(namespaceJson($labels, [NamespaceSecurityReconciler::ANNOTATION => 'observe']))]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unchanged');
    Process::assertRanTimes(ranKubectl('patch'), 0);
});

it('removes the labels it owns when the plan goes back to no tier', function () {
    $org_server = makeSecuredOrgServer('managed', null);
    $labels = ['pod-security.kubernetes.io/warn' => 'restricted', 'unrelated' => 'keep'];
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(namespaceJson($labels, [NamespaceSecurityReconciler::ANNOTATION => 'observe'])))
        ->push(Process::result('{}'))]);

    (new KubernetesNamespace($org_server->organization, $org_server))->update();

    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, '"pod-security.kubernetes.io/warn":null')
            && ! str_contains($command, 'unrelated');
    });
});

it('leaves labels it does not own alone when the plan has no tier', function () {
    $org_server = makeSecuredOrgServer('managed', null);
    Process::fake(['*' => Process::result(namespaceJson(['pod-security.kubernetes.io/enforce' => 'baseline']))]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unchanged');
    Process::assertRanTimes(ranKubectl('patch'), 0);
});

it('reports what it would change without changing it on a dry run', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    Process::fake(['*' => Process::result(namespaceJson())]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->reconcileSecurity(apply: false);

    expect($result['action'])->toBe('would_update')
        ->and($result['changes']['labels'])->toHaveKey('pod-security.kubernetes.io/warn');
    Process::assertRanTimes(ranKubectl('patch'), 0);
});

it('reports a failed patch (e.g. forbidden) without throwing', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(namespaceJson()))
        ->push(Process::result(output: '', errorOutput: 'namespaces "x" is forbidden', exitCode: 1))]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result)->toMatchArray(['status' => 'failed', 'action' => 'error'])
        ->and($result['response'])->toContain('forbidden');
});
