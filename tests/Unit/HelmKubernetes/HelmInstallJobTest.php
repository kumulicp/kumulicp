<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmInstallJob;

beforeEach(function () {
    config(['services.helm_runner.image' => 'test-registry/kumulicp-helm-runner:1.0.0']);
});

it('builds a Job manifest using the per-namespace installer identity, not kumulicp-deployer', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install', 'nextcloud-5iy7z', 'nextcloud']);

    $manifest = $job->jobManifest();

    expect($manifest['kind'])->toBe('Job');
    expect($manifest['metadata']['namespace'])->toBe('kumuli-demo');
    expect($manifest['spec']['template']['spec']['serviceAccountName'])->toBe('kumulicp-helm-installer');
    expect($manifest['spec']['template']['spec']['restartPolicy'])->toBe('Never');
    expect($manifest['spec']['backoffLimit'])->toBe(0);
    expect($manifest['spec']['template']['spec']['containers'][0]['image'])->toBe('test-registry/kumulicp-helm-runner:1.0.0');
    expect($manifest['spec']['template']['spec']['containers'][0]['args'])
        ->toBe(['upgrade', '--install', 'nextcloud-5iy7z', 'nextcloud']);
});

it('omits volumes and the values ConfigMap when there is no values file', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['uninstall', 'nextcloud-5iy7z', '--ignore-not-found']);

    expect($job->configMapManifest())->toBeNull();
    expect($job->jobManifest()['spec']['template']['spec'])->not->toHaveKey('volumes');
    expect($job->jobManifest()['spec']['template']['spec']['containers'][0])->not->toHaveKey('volumeMounts');
});

it('mounts a values ConfigMap at /values/values.yaml when a values file is given', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    $configMap = $job->configMapManifest();
    $manifest = $job->jobManifest();

    expect($configMap['kind'])->toBe('ConfigMap');
    expect($configMap['data']['values.yaml'])->toBe("replicaCount: 1\n");
    expect($manifest['spec']['template']['spec']['containers'][0]['volumeMounts'][0]['mountPath'])->toBe('/values');
    expect($manifest['spec']['template']['spec']['volumes'][0]['configMap']['name'])->toBe($job->configMapName());
});

it('builds a credentials Secret and wires it via envFrom when secretEnv is given, never as job args', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], null, [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    $secret = $job->secretManifest();
    $manifest = $job->jobManifest();

    expect($secret['kind'])->toBe('Secret');
    expect($secret['stringData'])->toBe(['HELM_REPO_USERNAME' => 'deploy', 'HELM_REPO_PASSWORD' => 'secret']);
    expect($manifest['spec']['template']['spec']['containers'][0]['envFrom'][0]['secretRef']['name'])->toBe($job->secretName());
    expect(json_encode($manifest['spec']['template']['spec']['containers'][0]['args']))->not->toContain('secret');
});

it('omits the Secret entirely when there are no credentials', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);

    expect($job->secretManifest())->toBeNull();
    expect($job->jobManifest()['spec']['template']['spec']['containers'][0])->not->toHaveKey('envFrom');
});

it('generates a unique job name per instance so concurrent installs cannot collide', function () {
    $a = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', []);
    $b = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', []);

    expect($a->jobName)->not->toBe($b->jobName);
    expect($a->labelSelector())->toBe("job-name={$a->jobName}");
});
