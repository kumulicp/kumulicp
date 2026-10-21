<?php

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\Job\WordpressJobChart;
use App\Organization;

function makeWordpressJob(array $command = ['/entrypoint.sh', '/update-domain.sh'], array $env = []): array
{
    $organization = Organization::factory()->create();
    // AppInstance has no factory
    $app_instance = new AppInstance;
    $application = Application::factory()->create(['slug' => 'wordpress']);
    $app_instance->application_id = $application->id;
    $app_instance->version_id = AppVersion::factory()->create(['application_id' => $application->id])->id;
    $app_instance->organization_id = $organization->id;
    $app_instance->name = 'my-wordpress';
    $app_instance->label = 'my-wordpress';
    $app_instance->api_password = '';
    $app_instance->status = 'active';
    $app_instance->save();
    $app_instance->updateSetting('override.chart.wordpress.name', 'my-wordpress');

    // Stub the lookups that would otherwise need an image version or a cluster
    $chart = Mockery::mock(WordpressJobChart::class, [$organization, $app_instance])->makePartial();
    $chart->shouldReceive('image')->andReturn('registry.example.test/wordpress:1.0');
    $chart->shouldReceive('imagePullSecrets')->andReturn([]);
    $chart->shouldReceive('persistentVolumeClaim')->andReturn('wordpress-pvc');

    $chart->run($command, $env, 'test-job');

    return $chart->chart;
}

function wordpressJobPod(array $job): array
{
    return $job['spec']['template']['spec'];
}

it('runs the job as a non-root user with the restricted pod security settings', function () {
    $pod = wordpressJobPod(makeWordpressJob());

    expect($pod['securityContext'])->toMatchArray([
        'runAsNonRoot' => true,
        'runAsUser' => 33,
        'runAsGroup' => 33,
        'fsGroup' => 33,
        'seccompProfile' => ['type' => 'RuntimeDefault'],
    ]);
});

it('drops all privileges and uses a read-only root filesystem in the job container', function () {
    $container = wordpressJobPod(makeWordpressJob())['containers'][0];

    expect($container['securityContext'])->toBe([
        'allowPrivilegeEscalation' => false,
        'capabilities' => ['drop' => ['ALL']],
        'privileged' => false,
        'readOnlyRootFilesystem' => true,
        'runAsGroup' => 33,
        'runAsNonRoot' => true,
        'runAsUser' => 33,
        'seccompProfile' => ['type' => 'RuntimeDefault'],
    ]);
});

it('uses the same unprivileged port as the hardened wordpress chart', function () {
    $container = wordpressJobPod(makeWordpressJob())['containers'][0];

    expect($container['ports'][0]['containerPort'])->toBe(8080);
});

it('gives the read-only container a writable /tmp that encodes as an empty object', function () {
    $pod = wordpressJobPod(makeWordpressJob());

    expect(collect($pod['containers'][0]['volumeMounts'])->firstWhere('name', 'tmp')['mountPath'])->toBe('/tmp');

    $tmp = collect($pod['volumes'])->firstWhere('name', 'tmp');

    // An empty PHP array would encode as a JSON list, which the API rejects for emptyDir
    expect(json_encode($tmp))->toBe('{"name":"tmp","emptyDir":{}}');
});

it('still mounts the persistent volume and passes the command and env through', function () {
    $job = makeWordpressJob(['/entrypoint.sh', '/update-domain.sh'], [['name' => 'APP_URL', 'value' => 'https://example.test']]);
    $pod = wordpressJobPod($job);
    $container = $pod['containers'][0];

    expect($container['command'])->toBe(['/entrypoint.sh', '/update-domain.sh'])
        ->and($container['env'])->toBe([['name' => 'APP_URL', 'value' => 'https://example.test']])
        ->and(collect($container['volumeMounts'])->firstWhere('name', 'wordpress-storage'))
        ->toMatchArray(['mountPath' => '/wordpress', 'subPath' => 'wordpress'])
        ->and(collect($pod['volumes'])->firstWhere('name', 'wordpress-storage')['persistentVolumeClaim']['claimName'])
        ->toBe('wordpress-pvc')
        ->and($job['metadata']['name'])->toBe('test-job');
});
