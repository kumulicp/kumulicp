<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmInstallJob;

beforeEach(function () {
    config(['services.helm_runner.image' => 'test-registry/kumulicp-helm-runner:1.0.0']);
});

it('builds a Job manifest using its own fresh per-release ServiceAccount, not kumulicp-deployer', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install', 'nextcloud-5iy7z', 'nextcloud']);

    $manifest = $job->jobManifest();

    expect($manifest['kind'])->toBe('Job');
    expect($manifest['metadata']['namespace'])->toBe('kumuli-demo');
    expect($manifest['spec']['template']['spec']['serviceAccountName'])->toBe($job->serviceAccountName());
    expect($job->serviceAccountName())->not->toBe('kumulicp-deployer');
    expect($manifest['spec']['template']['spec']['restartPolicy'])->toBe('Never');
    expect($manifest['spec']['backoffLimit'])->toBe(0);
    expect($manifest['spec']['template']['spec']['containers'][0]['image'])->toBe('test-registry/kumulicp-helm-runner:1.0.0');
    expect($manifest['spec']['template']['spec']['containers'][0]['command'])->toBe(['/bin/sh', '-c']);
    // args[0] is the entrypoint script, args[1] is $0 (the "sh" convention
    // for `sh -c script argv0 args...`), the actual helm subcommand follows.
    expect(array_slice($manifest['spec']['template']['spec']['containers'][0]['args'], 2))
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
    expect(json_encode($manifest['spec']['template']['spec']['containers'][0]['args']))->not->toContain('deploy');
    expect(json_encode($manifest['spec']['template']['spec']['containers'][0]['args']))->not->toContain('"secret"');
});

it('embeds the credential-injection script so no custom image is needed', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);

    $script = $job->jobManifest()['spec']['template']['spec']['containers'][0]['args'][0];

    expect($script)->toContain('set -e');
    expect($script)->toContain('helm registry login');
    expect($script)->toContain('OCI_REGISTRY_HOST');
    expect($script)->toContain('HELM_REPO_USERNAME');
    expect($script)->toContain('exec helm "$@"');
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

it('omits ownerReferences when no Job uid is given yet', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
    ]);

    expect($job->configMapManifest())->not->toHaveKey('metadata.ownerReferences');
    expect($job->secretManifest())->not->toHaveKey('metadata.ownerReferences');
});

it('adds an ownerReference pointing at the Job once its uid is given', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
    ]);

    $configMapOwner = $job->configMapManifest('job-uid-123')['metadata']['ownerReferences'][0];
    $secretOwner = $job->secretManifest('job-uid-123')['metadata']['ownerReferences'][0];
    $serviceAccountOwner = $job->serviceAccountManifest('job-uid-123')['metadata']['ownerReferences'][0];
    $roleBindingOwner = $job->roleBindingManifest('job-uid-123')['metadata']['ownerReferences'][0];

    foreach ([$configMapOwner, $secretOwner, $serviceAccountOwner, $roleBindingOwner] as $owner) {
        expect($owner['kind'])->toBe('Job');
        expect($owner['name'])->toBe($job->jobName);
        expect($owner['uid'])->toBe('job-uid-123');
    }
});

it('names the ServiceAccount and RoleBinding after the job so each release gets its own fresh identity', function () {
    $a = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);
    $b = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);

    expect($a->serviceAccountName())->toBe($a->jobName);
    expect($a->serviceAccountName())->not->toBe($b->serviceAccountName());
});

it('builds a ServiceAccount manifest without ownerReferences until a Job uid is given', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);

    $manifest = $job->serviceAccountManifest();

    expect($manifest['kind'])->toBe('ServiceAccount');
    expect($manifest['metadata']['name'])->toBe($job->serviceAccountName());
    expect($manifest['metadata']['namespace'])->toBe('kumuli-demo');
    expect($manifest['metadata'])->not->toHaveKey('ownerReferences');
});

it('builds a RoleBinding manifest binding the per-release ServiceAccount to the shared installer ClusterRole', function () {
    $job = new HelmInstallJob('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);

    $manifest = $job->roleBindingManifest();

    expect($manifest['kind'])->toBe('RoleBinding');
    expect($manifest['metadata']['name'])->toBe($job->serviceAccountName());
    expect($manifest['metadata']['namespace'])->toBe('kumuli-demo');
    expect($manifest['subjects'][0])->toBe([
        'kind' => 'ServiceAccount',
        'name' => $job->serviceAccountName(),
        'namespace' => 'kumuli-demo',
    ]);
    expect($manifest['roleRef'])->toBe([
        'kind' => 'ClusterRole',
        'name' => 'kumulicp-helm-installer',
        'apiGroup' => 'rbac.authorization.k8s.io',
    ]);
});
