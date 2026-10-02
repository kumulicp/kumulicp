<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\Secret;
use App\Integrations\ServerManagers\Rancher\Charts\ChartSecrets;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

function makeSecretApi(): Secret
{
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    $organization = Organization::factory()->create();

    $org_server = OrgServer::create([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
    ]);

    return new Secret($organization, $org_server);
}

it('renders a standard v1 Secret with base64 data sorted by key', function () {
    $store = (new ChartSecrets('demo', 'demo-wordpress-secrets', 'demo-wordpress'))
        ->set('db-password', 'p@ss word')
        ->set('admin-password', 'hunter2');

    $manifest = $store->manifest();

    expect($manifest['apiVersion'])->toBe('v1')
        ->and($manifest['kind'])->toBe('Secret')
        ->and($manifest['type'])->toBe('Opaque')
        ->and($manifest['metadata']['name'])->toBe('demo-wordpress-secrets')
        ->and($manifest['metadata']['namespace'])->toBe('demo')
        ->and($manifest['metadata']['labels'])->toBe([
            'app.kubernetes.io/managed-by' => 'kumulicp',
            'kumulicp.io/release' => 'demo-wordpress',
        ])
        ->and(array_keys($manifest['data']))->toBe(['admin-password', 'db-password'])
        ->and(base64_decode($manifest['data']['db-password']))->toBe('p@ss word');
});

it('dumps yaml that round-trips to the same manifest', function () {
    $store = (new ChartSecrets('demo', 'demo-secrets'))->set('token', "multi\nline");

    expect(Yaml::parse($store->toYaml()))->toBe($store->manifest());
});

it('builds secret references for chart values and container env', function () {
    $store = (new ChartSecrets('demo', 'demo-secrets'))->set('db-password', 'x');

    expect($store->ref('db-password'))->toBe(['name' => 'demo-secrets', 'key' => 'db-password'])
        ->and($store->envVar('DB_PASSWORD', 'db-password'))->toBe([
            'name' => 'DB_PASSWORD',
            'valueFrom' => ['secretKeyRef' => ['name' => 'demo-secrets', 'key' => 'db-password']],
        ]);
});

it('never exposes a secret value in a reference', function () {
    $store = (new ChartSecrets('demo', 'demo-secrets'))->set('db-password', 'super-secret-value');

    expect(json_encode([$store->ref('db-password'), $store->envVar('DB_PASSWORD', 'db-password')]))
        ->not->toContain('super-secret-value');
});

it('stores null as an empty string so references never dangle', function () {
    $store = (new ChartSecrets('demo', 'demo-secrets'))->set('smtp-password', null);

    expect($store->has('smtp-password'))->toBeTrue()
        ->and(base64_decode($store->manifest()['data']['smtp-password']))->toBe('');
});

it('rejects invalid keys and references to unset keys', function () {
    $store = new ChartSecrets('demo', 'demo-secrets');

    expect(fn () => $store->set('bad key!', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->ref('missing'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->envVar('X', 'missing'))->toThrow(InvalidArgumentException::class);
});

it('adds an ownerReference to a job only when given one', function () {
    $store = (new ChartSecrets('demo', 'demo-job-secrets'))->set('k', 'v');

    expect($store->manifest()['metadata'])->not->toHaveKey('ownerReferences')
        ->and($store->manifest('uid-123', 'demo-job')['metadata']['ownerReferences'][0])->toMatchArray([
            'kind' => 'Job',
            'name' => 'demo-job',
            'uid' => 'uid-123',
        ]);
});

it('applies a store as a Secret manifest piped over stdin, not as an argument', function () {
    Process::fake(fn () => Process::result(json_encode(['metadata' => ['name' => 'demo-secrets']])));

    $store = (new ChartSecrets('demo', 'demo-secrets'))->set('db-password', 'super-secret-value');
    $result = makeSecretApi()->applyStore($store);

    expect($result['status'])->toBe('success');

    Process::assertRan(function ($process) {
        $manifest = json_decode($process->input, true);

        return in_array('apply', (array) $process->command, true)
            && ($manifest['kind'] ?? null) === 'Secret'
            && base64_decode($manifest['data']['db-password']) === 'super-secret-value'
            && ! str_contains(implode(' ', (array) $process->command), 'super-secret-value');
    });
});

it('does not touch the cluster for an empty store', function () {
    Process::fake();

    $result = makeSecretApi()->applyStore(new ChartSecrets('demo', 'demo-secrets'));

    expect($result['status'])->toBe('success');
    Process::assertNothingRan();
});

it('reports a failed apply without leaking the manifest', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: 'forbidden', exitCode: 1));

    $store = (new ChartSecrets('demo', 'demo-secrets'))->set('db-password', 'super-secret-value');
    $result = makeSecretApi()->applyStore($store);

    expect($result['status'])->toBe('failed')
        ->and(json_encode($result))->not->toContain('super-secret-value');
});

it('deletes a store by name', function () {
    Process::fake(fn () => Process::result(''));

    makeSecretApi()->removeStore(new ChartSecrets('demo', 'demo-secrets'));

    Process::assertRan(fn ($process) => in_array('delete', (array) $process->command, true)
        && in_array('secret', (array) $process->command, true)
        && in_array('demo-secrets', (array) $process->command, true));
});
