<?php

use App\Support\Catalog\ChartConventionDetector;

// Trimmed copy of the default values of the HelmForge gitea chart: flat persistence
function flatChartDefaults(): array
{
    return [
        'replicaCount' => 1,
        'fullnameOverride' => '',
        'image' => ['repository' => 'docker.io/gitea/gitea', 'tag' => '1.2.3'],
        'imagePullSecrets' => [],
        'persistence' => ['enabled' => true, 'storageClass' => '', 'accessMode' => 'ReadWriteOnce', 'size' => '10Gi', 'existingClaim' => ''],
        'resources' => [],
        'ingress' => ['enabled' => false, 'ingressClassName' => '', 'annotations' => [], 'hosts' => [], 'tls' => []],
        'podLabels' => [],
        'admin' => ['username' => 'admin', 'password' => ''],
    ];
}

// ...and of paperless-ngx: several persistent volumes and a nested admin
function groupedChartDefaults(): array
{
    return [
        'replicaCount' => 1,
        'persistence' => [
            'data' => ['enabled' => true, 'size' => '2Gi', 'storageClass' => '', 'existingClaim' => ''],
            'media' => ['enabled' => true, 'size' => '10Gi', 'storageClass' => '', 'existingClaim' => ''],
            'consume' => ['enabled' => false, 'size' => '5Gi', 'storageClass' => '', 'existingClaim' => ''],
        ],
        'resources' => ['requests' => ['cpu' => '250m', 'memory' => '768Mi'], 'limits' => ['cpu' => '2000m', 'memory' => '2Gi']],
        'ingress' => ['enabled' => false, 'hosts' => [], 'tls' => []],
        'paperless' => ['admin' => ['enabled' => true, 'username' => 'admin', 'password' => '']],
    ];
}

it('detects the keys of a chart with flat persistence', function () {
    $convention = (new ChartConventionDetector)->detect(flatChartDefaults());

    expect($convention)->toMatchArray([
        'replicas' => 'replicaCount',
        'fullname' => 'fullnameOverride',
        'labels' => ['podLabels'],
        'resources' => 'resources',
        'admin_password' => 'admin.password',
        'pull_secrets' => ['path' => 'imagePullSecrets', 'format' => 'object'],
        'ingress' => ['path' => 'ingress', 'style' => 'hosts'],
        'persistence' => [
            'size' => 'persistence.size',
            'storage_class' => 'persistence.storageClass',
            'existing_claim' => 'persistence.existingClaim',
        ],
    ]);
});

it('picks the first enabled volume of a chart with several', function () {
    $convention = (new ChartConventionDetector)->detect(groupedChartDefaults());

    expect($convention['persistence']['size'])->toBe('persistence.data.size');
    expect($convention['admin_password'])->toBe('paperless.admin.password');
});

it('lets a per-chart override choose the volume and replace detected keys', function () {
    $convention = (new ChartConventionDetector)->detect(groupedChartDefaults(), [
        'persistence_volume' => 'media',
        'admin_password' => 'custom.password',
    ]);

    expect($convention['persistence']['size'])->toBe('persistence.media.size');
    expect($convention['admin_password'])->toBe('custom.password');
});

it('prefers commonLabels over pod labels', function () {
    $convention = (new ChartConventionDetector)->detect(['commonLabels' => [], 'podLabels' => []]);

    expect($convention['labels'])->toBe(['commonLabels']);
});

it('leaves out concerns the chart does not have', function () {
    $convention = (new ChartConventionDetector)->detect(['image' => ['tag' => '1']]);

    expect($convention)->toMatchArray([
        'replicas' => null,
        'fullname' => null,
        'labels' => [],
        'ingress' => null,
        'persistence' => null,
        'resources' => null,
        'pull_secrets' => null,
        'admin_password' => null,
    ]);
    expect((new ChartConventionDetector)->configurations($convention, []))->toBe([]);
});

it('builds plan options only for the supported concerns, defaulted from the chart', function () {
    $detector = new ChartConventionDetector;
    $configs = $detector->configurations($detector->detect(groupedChartDefaults()), groupedChartDefaults());

    expect(array_keys($configs))->toEqualCanonicalizing([
        'replicaCount',
        'persistence-storageClass',
        'resources-requests-cpu',
        'resources-requests-memory',
        'resources-limits-cpu',
        'resources-limits-memory',
        'ingress-enabled',
        'ingress-tls',
        'ingress-annotation-cluster_issuer',
        'ingress-annotation-traefik_middlewares',
    ]);
    expect($configs['resources-limits-memory']['default'])->toBe('2Gi');
    expect($configs['replicaCount']['default'])->toBe(1);
});
