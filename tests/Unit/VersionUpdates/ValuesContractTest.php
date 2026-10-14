<?php

use App\Support\VersionUpdates\ValuesContract;

it('reports values Kumulicp sets that the new chart dropped', function () {
    $rendered = [
        'image' => ['repository' => 'nextcloud', 'tag' => '33.0.1'],
        'ingress' => ['annotations' => ['cert-manager.io/cluster-issuer' => 'letsencrypt']],
        'customSetting' => 'kept-out-of-defaults',
    ];
    $current = [
        'image' => ['repository' => '', 'tag' => ''],
        'ingress' => ['annotations' => []],
    ];
    $candidate = [
        'image' => ['repository' => '', 'version' => ''],
        'ingress' => [],
    ];

    expect((new ValuesContract)->removedKeys($rendered, $current, $candidate))
        ->toBe(['image.tag', 'ingress.annotations']);
});

it('falls back to dropped defaults when there are no rendered values', function () {
    $current = ['persistence' => ['enabled' => true, 'size' => '8Gi'], 'replicas' => 1];
    $candidate = ['persistence' => ['enabled' => true], 'replicas' => 1];

    expect((new ValuesContract)->removedKeys(null, $current, $candidate))->toBe(['persistence.size']);
});

it('validates values against the chart schema', function () {
    $schema = [
        'type' => 'object',
        'required' => ['image'],
        'properties' => [
            'image' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'tag' => ['type' => 'string'],
                    'pullPolicy' => ['enum' => ['Always', 'IfNotPresent']],
                ],
            ],
            'replicaCount' => ['type' => 'integer'],
        ],
    ];

    $errors = (new ValuesContract)->schemaErrors([
        'image' => ['tag' => 33, 'pullPolicy' => 'Sometimes', 'registry' => 'docker.io'],
        'replicaCount' => null,
    ], $schema);

    expect($errors)->toHaveCount(3)
        ->and(implode(' ', $errors))->toContain('image.tag', 'image.pullPolicy', 'image.registry');
});

it('accepts values that match the schema', function () {
    $schema = ['type' => 'object', 'properties' => ['hosts' => ['type' => 'array', 'items' => ['type' => 'string']]]];

    expect((new ValuesContract)->schemaErrors(['hosts' => ['a.example.com']], $schema))->toBe([]);
});
