<?php

use App\Support\Security\Tools\KubescapeTool;
use App\Support\Security\Tools\NucleiTool;
use App\Support\Security\Tools\PolarisTool;
use App\Support\Security\Tools\TrivyTool;

test('nuclei builds a -target flag pair for each selected domain', function () {
    $command = (new NucleiTool)->command(['example.com', 'foo.example.com']);

    expect($command)->toBe([
        'nuclei', '-jsonl', '-or', '-ot',
        '-target', 'example.com',
        '-target', 'foo.example.com',
    ]);
});

test('nuclei command has no target flags when no targets are given', function () {
    expect((new NucleiTool)->command())->toBe(['nuclei', '-jsonl', '-or', '-ot']);
});

test('nuclei requires targets', function () {
    expect((new NucleiTool)->requiresTargets())->toBeTrue();
});

test('cluster-scanning tools ignore targets and do not require them', function () {
    $tool = new KubescapeTool;

    expect($tool->requiresTargets())->toBeFalse()
        ->and($tool->command(['example.com']))->toBe($tool->command());
});

test('trivy appends a --severity flag for the selected severities', function () {
    $command = (new TrivyTool)->command([], ['severity' => ['HIGH', 'CRITICAL']]);

    expect($command)->toContain('--severity', 'HIGH,CRITICAL');
});

test('trivy ignores unknown severity values', function () {
    $command = (new TrivyTool)->command([], ['severity' => ['HIGH', 'NOT-A-SEVERITY']]);

    expect($command)->toContain('--severity', 'HIGH')
        ->and(implode(' ', $command))->not->toContain('NOT-A-SEVERITY');
});

test('trivy appends --ignore-unfixed only when requested', function () {
    expect((new TrivyTool)->command([], ['ignore_unfixed' => true]))->toContain('--ignore-unfixed')
        ->and((new TrivyTool)->command())->not->toContain('--ignore-unfixed');
});

test('trivy command has no severity flag when no severities are given', function () {
    $command = (new TrivyTool)->command();

    expect($command)->not->toContain('--severity');
});

test('trivy supports the severity filter and other tools do not', function () {
    expect((new TrivyTool)->supportsSeverityFilter())->toBeTrue()
        ->and((new KubescapeTool)->supportsSeverityFilter())->toBeFalse()
        ->and((new NucleiTool)->supportsSeverityFilter())->toBeFalse();
});

test('trivy appends a --include-namespaces flag for the selected namespaces', function () {
    $command = (new TrivyTool)->command([], ['namespaces' => ['demo', 'kube-system']]);

    expect($command)->toContain('--include-namespaces', 'demo,kube-system');
});

test('trivy command has no namespace flag when no namespaces are given', function () {
    expect((new TrivyTool)->command())->not->toContain('--include-namespaces');
});

test('trivy supports the namespace filter and other tools do not', function () {
    expect((new TrivyTool)->supportsNamespaceFilter())->toBeTrue()
        ->and((new KubescapeTool)->supportsNamespaceFilter())->toBeFalse()
        ->and((new NucleiTool)->supportsNamespaceFilter())->toBeFalse();
});

test('polaris appends a --namespace flag for the first selected namespace', function () {
    $command = (new PolarisTool)->command([], ['namespaces' => ['demo', 'kube-system']]);

    expect($command)->toContain('--namespace', 'demo')
        ->and(implode(' ', $command))->not->toContain('kube-system');
});

test('polaris command has no namespace flag when no namespaces are given', function () {
    expect((new PolarisTool)->command())->not->toContain('--namespace');
});

test('polaris supports the namespace filter', function () {
    expect((new PolarisTool)->supportsNamespaceFilter())->toBeTrue();
});

test('polaris only allows a single namespace at a time, unlike trivy', function () {
    expect((new PolarisTool)->namespaceFilterAllowsMultiple())->toBeFalse()
        ->and((new TrivyTool)->namespaceFilterAllowsMultiple())->toBeTrue();
});