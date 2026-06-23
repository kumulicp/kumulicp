<?php

use App\Support\Security\Parsers\KubeBenchParser;
use App\Support\Security\Parsers\KubeHunterParser;
use App\Support\Security\Parsers\NucleiParser;
use App\Support\Security\Parsers\ParserFactory;

test('kube-hunter parser extracts vulnerabilities with mapped severity', function () {
    $raw = json_encode([
        'vulnerabilities' => [
            [
                'vulnerability' => 'Exposed kubelet read-only port',
                'severity' => 'high',
                'category' => 'Information Disclosure',
                'description' => 'kubelet read-only port is exposed',
                'evidence' => 'port 10255 open',
                'vid' => 'KHV001',
            ],
        ],
    ]);

    $findings = (new KubeHunterParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('high')
        ->and($findings[0]['rule_id'])->toBe('KHV001');
});

test('kube-bench parser only reports failed checks', function () {
    $raw = json_encode([
        'Controls' => [
            [
                'text' => '1 Control Plane',
                'tests' => [
                    [
                        'results' => [
                            ['status' => 'PASS', 'test_desc' => 'ok check', 'test_number' => '1.1', 'scored' => true],
                            ['status' => 'FAIL', 'test_desc' => 'Anonymous auth enabled', 'test_number' => '1.2', 'scored' => true, 'remediation' => 'disable it'],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $findings = (new KubeBenchParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['title'])->toBe('Anonymous auth enabled')
        ->and($findings[0]['severity'])->toBe('high');
});

test('nuclei parser reads newline-delimited json events', function () {
    $raw = json_encode(['template-id' => 'exposed-panel', 'matched-at' => 'https://example.com', 'info' => ['name' => 'Exposed admin panel', 'severity' => 'medium']])."\n";

    $findings = (new NucleiParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('medium')
        ->and($findings[0]['rule_id'])->toBe('exposed-panel');
});

test('parser factory resolves a parser for every registered tool', function () {
    foreach (array_keys(ParserFactory::MAP) as $tool) {
        expect(ParserFactory::make($tool))->toBeInstanceOf(\App\Support\Security\Parsers\Parser::class);
    }
});

test('parser factory throws for unknown tool', function () {
    expect(fn () => ParserFactory::make('not-a-tool'))->toThrow(InvalidArgumentException::class);
});
