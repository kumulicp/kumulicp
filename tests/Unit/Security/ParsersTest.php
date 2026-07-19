<?php

use App\Support\Facades\SecurityTool;
use App\Support\Security\Parsers\KubeBenchParser;
use App\Support\Security\Parsers\KubeHunterParser;
use App\Support\Security\Parsers\KubescapeParser;
use App\Support\Security\Parsers\NucleiParser;
use App\Support\Security\Parsers\ParserFactory;
use App\Support\Security\Parsers\PolarisParser;
use App\Support\Security\Parsers\TrivyParser;

test('kube-hunter parser extracts vulnerabilities with mapped severity', function () {
    $report = json_encode([
        'vulnerabilities' => [
            [
                'vulnerability' => 'Exposed kubelet read-only port',
                'location' => '10.42.0.6:10250',
                'severity' => 'high',
                'category' => 'Information Disclosure',
                'description' => 'kubelet read-only port is exposed',
                'evidence' => 'port 10255 open',
                'avd_reference' => 'https://avd.aquasec.com/kube-hunter/khv001/',
                'hunter' => 'Kubelet Hunter',
                'vid' => 'KHV001',
            ],
        ],
    ]);

    // `kube-hunter` prints its own log lines before the JSON report.
    $raw = "2026-07-19 16:26:37,617 INFO kube_hunter.modules.report.collector Started hunting\n".$report;

    $findings = (new KubeHunterParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('high')
        ->and($findings[0]['rule_id'])->toBe('KHV001')
        ->and($findings[0]['resource_name'])->toBe('10.42.0.6:10250')
        // "evidence" is proof of the finding, not remediation guidance - it
        // can contain live tokens/secrets, so it belongs in metadata (shown
        // in the details view on demand) rather than the remediation field.
        ->and($findings[0]['remediation'])->toBe('See https://avd.aquasec.com/kube-hunter/khv001/ for remediation guidance.')
        ->and($findings[0]['metadata'])->toBe([
            'hunter' => 'Kubelet Hunter',
            'evidence' => 'port 10255 open',
        ]);
});

test('kube-hunter parser treats a "None" vid as no rule id', function () {
    $raw = json_encode([
        'vulnerabilities' => [
            ['vulnerability' => 'CAP_NET_RAW Enabled', 'severity' => 'medium', 'vid' => 'None', 'evidence' => ''],
        ],
    ]);

    $findings = (new KubeHunterParser)->parse($raw);

    expect($findings[0]['rule_id'])->toBeNull()
        ->and($findings[0]['metadata'])->toBeNull();
});

test('kube-bench parser only reports failed checks', function () {
    $raw = json_encode([
        'Controls' => [
            [
                'text' => '1 Control Plane',
                'node_type' => 'master',
                'tests' => [
                    [
                        'results' => [
                            ['status' => 'PASS', 'test_desc' => 'ok check', 'test_number' => '1.1', 'scored' => true],
                            [
                                'status' => 'FAIL',
                                'test_desc' => 'Anonymous auth enabled',
                                'test_number' => '1.2',
                                'scored' => true,
                                'remediation' => 'disable it',
                                'expected_result' => "'enabled' is false",
                                'actual_value' => 'true',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $findings = (new KubeBenchParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['title'])->toBe('Anonymous auth enabled')
        ->and($findings[0]['severity'])->toBe('high')
        ->and($findings[0]['resource_type'])->toBe('Node')
        ->and($findings[0]['resource_name'])->toBe('master')
        ->and($findings[0]['metadata'])->toBe([
            'expected_result' => "'enabled' is false",
            'actual_value' => 'true',
        ]);
});

test('kube-bench parser extracts the report from noisy pod logs', function () {
    $report = json_encode([
        'Controls' => [[
            'text' => '1 Control Plane',
            'node_type' => 'master',
            'tests' => [[
                'results' => [
                    ['status' => 'FAIL', 'test_desc' => 'Anonymous auth enabled', 'test_number' => '1.2', 'scored' => true],
                ],
            ]],
        ]],
    ]);

    $raw = "level=info msg=\"Starting kube-bench\"\n".$report;

    $findings = (new KubeBenchParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['title'])->toBe('Anonymous auth enabled');
});

test('nuclei parser reads newline-delimited json events', function () {
    $raw = json_encode(['template-id' => 'exposed-panel', 'matched-at' => 'https://example.com', 'info' => ['name' => 'Exposed admin panel', 'severity' => 'medium']])."\n";

    $findings = (new NucleiParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('medium')
        ->and($findings[0]['rule_id'])->toBe('exposed-panel');
});

test('nuclei parser skips ansi-colored banner lines and uses the real description', function () {
    $event = json_encode([
        'template-id' => 'wildcard-dns-detect',
        'matched-at' => 'sub.example.com',
        'info' => [
            'name' => 'Wildcard DNS Configuration - Detection',
            'severity' => 'info',
            'tags' => ['dns', 'wildcard', 'discovery'],
            'description' => 'A wildcard DNS configuration was detected.',
            'reference' => ['https://en.wikipedia.org/wiki/Wildcard_DNS_record'],
        ],
    ]);

    $raw = implode("\n", [
        "\033[34mINF\033[0m Templates loaded for current scan: 10516",
        $event,
        "\033[34mINF\033[0m Scan completed in 12m. 1 matches found.",
    ]);

    $findings = (new NucleiParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['description'])->toBe("Matched at: sub.example.com\n\nA wildcard DNS configuration was detected.")
        ->and($findings[0]['remediation'])->toBe('See: https://en.wikipedia.org/wiki/Wildcard_DNS_record');
});

test('nuclei parser falls back to matched-at when there is no template description', function () {
    $raw = json_encode(['template-id' => 'exposed-panel', 'matched-at' => 'https://example.com', 'info' => ['name' => 'Exposed admin panel', 'severity' => 'medium']]);

    $findings = (new NucleiParser)->parse($raw);

    expect($findings[0]['description'])->toBe('Matched at: https://example.com')
        ->and($findings[0]['remediation'])->toBeNull();
});

test('kubescape parser extracts the report from noisy pod logs', function () {
    // Real kubescape v4 JSON schema: per-result controls only carry
    // controlID/name/status/severity - category comes from a separate
    // controlID-keyed catalog in summaryDetails, and there's no
    // description/remediation text anywhere in the report.
    $report = json_encode([
        'summaryDetails' => [
            'controls' => [
                'C-0271' => ['category' => ['name' => 'Resource management']],
            ],
        ],
        'results' => [
            [
                'resourceID' => 'apps/v1/default/Deployment/my-app',
                'controls' => [
                    [
                        'status' => ['status' => 'passed'],
                        'name' => 'Ignored passing control',
                    ],
                    [
                        'status' => ['status' => 'failed'],
                        'name' => 'Ensure memory limits are set',
                        'controlID' => 'C-0271',
                        'severity' => 'High',
                    ],
                ],
            ],
        ],
    ]);

    // kubectl logs merges stdout/stderr, so the real raw_output has log lines
    // and the pretty-printer summary before the report, which `--format json`
    // (without -o) prints as a single compact line at the very end.
    $raw = implode("\n", [
        '{"level":"info","ts":"2026-07-16T20:42:46Z","msg":"Kubescape scanner initializing..."}',
        '{"level":"info","ts":"2026-07-16T20:42:50Z","msg":"Done scanning"}',
        'Compliance Score',
        '────────────────',
        '* NSA: 57.80%',
        $report,
    ]);

    $findings = (new KubescapeParser)->parse($raw);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe('high')
        ->and($findings[0]['title'])->toBe('Ensure memory limits are set')
        ->and($findings[0]['category'])->toBe('Resource management')
        ->and($findings[0]['resource_type'])->toBe('Deployment')
        ->and($findings[0]['resource_name'])->toBe('default/my-app')
        ->and($findings[0]['remediation'])->toBe('See https://kubescape.io/docs/controls/c-0271 for remediation guidance.')
        ->and($findings[0]['rule_id'])->toBe('C-0271');
});

test('kubescape parser handles cluster-scoped and rbac-chain resource ids', function () {
    $report = fn ($resource_id) => json_encode([
        'results' => [[
            'resourceID' => $resource_id,
            'controls' => [[
                'status' => ['status' => 'failed'],
                'name' => 'A failed control',
                'controlID' => 'C-0001',
            ]],
        ]],
    ]);

    $clusterScoped = (new KubescapeParser)->parse($report('rbac.authorization.k8s.io/v1//ClusterRoleBinding/system:controller:foo'));
    expect($clusterScoped[0]['resource_type'])->toBe('ClusterRoleBinding')
        ->and($clusterScoped[0]['resource_name'])->toBe('system:controller:foo');

    // Access-control findings concatenate subject/role/rolebinding tuples,
    // which would otherwise make "User" look like a namespace.
    $rbacChain = (new KubescapeParser)->parse($report('rbac.authorization.k8s.io//User/some-user/rbac.authorization.k8s.io/v1/local/RoleBinding/rb-abc123'));
    expect($rbacChain[0]['resource_type'])->toBe('RoleBinding')
        ->and($rbacChain[0]['resource_name'])->toBe('rb-abc123');
});

test('kubescape parser returns no findings when no report is present', function () {
    $findings = (new KubescapeParser)->parse('{"level":"info","msg":"scan failed before producing a report"}');

    expect($findings)->toBe([]);
});

test('kubescape parser recovers a report with a raw newline injected inside a string value', function () {
    $report = json_encode([
        'results' => [[
            'controls' => [[
                'status' => ['status' => 'failed'],
                'name' => 'Long description text',
                'controlID' => 'C-0271',
                'severity' => 'High',
            ]],
        ]],
    ]);

    // Simulate a very long line getting split by an intermediate log
    // transport layer, landing a literal newline byte inside a string value.
    $corrupted = str_replace('Long description text', "Long description\ntext", $report);

    $findings = (new KubescapeParser)->parse("{\"level\":\"info\"}\n".$corrupted);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['title'])->toBe("Long description\ntext");
});

test('polaris parser reads controller, pod, and container level checks', function () {
    $report = json_encode([
        'Results' => [
            [
                'Name' => 'my-app',
                'Namespace' => 'default',
                'Kind' => 'Deployment',
                'Results' => [
                    'metadataAndInstanceMismatched' => [
                        'ID' => 'metadataAndInstanceMismatched',
                        'Message' => 'Label app.kubernetes.io/instance must match metadata.name',
                        'Success' => false,
                        'Severity' => 'warning',
                        'Category' => 'Reliability',
                    ],
                ],
                'PodResult' => [
                    'Name' => '',
                    'Results' => [
                        'hostIPCSet' => [
                            'ID' => 'hostIPCSet',
                            'Message' => 'Host IPC should not be configured',
                            'Success' => true,
                            'Severity' => 'danger',
                            'Category' => 'Security',
                        ],
                    ],
                    'ContainerResults' => [
                        [
                            'Name' => 'app',
                            'Results' => [
                                'runAsRootAllowed' => [
                                    'ID' => 'runAsRootAllowed',
                                    'Message' => 'Should not be allowed to run as root',
                                    'Success' => false,
                                    'Severity' => 'danger',
                                    'Category' => 'Security',
                                    'Mutations' => null,
                                ],
                                'pullPolicyNotAlways' => [
                                    'ID' => 'pullPolicyNotAlways',
                                    'Message' => 'Image pull policy should be "Always"',
                                    'Success' => false,
                                    'Severity' => 'warning',
                                    'Category' => 'Reliability',
                                    'Mutations' => [
                                        ['Path' => '/spec/template/spec/containers/0/imagePullPolicy', 'Op' => 'add', 'Value' => 'Always'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    // `polaris audit` prints its own log lines before the JSON report.
    $raw = "time=\"2026-07-17T22:42:28Z\" level=info msg=\"Done loading Kubernetes resources\"\n".$report;

    $findings = (new PolarisParser)->parse($raw);

    expect($findings)->toHaveCount(3);

    $controllerCheck = collect($findings)->firstWhere('rule_id', 'metadataAndInstanceMismatched');
    expect($controllerCheck['resource_type'])->toBe('Deployment')
        ->and($controllerCheck['resource_name'])->toBe('default/my-app')
        ->and($controllerCheck['metadata'])->toBeNull();

    $containerCheck = collect($findings)->firstWhere('rule_id', 'runAsRootAllowed');
    expect($containerCheck['resource_type'])->toBe('Deployment')
        ->and($containerCheck['resource_name'])->toBe('default/my-app')
        ->and($containerCheck['metadata'])->toBe(['container' => 'app'])
        ->and($containerCheck['severity'])->toBe('high')
        ->and($containerCheck['remediation'])->toBeNull();

    $mutatedCheck = collect($findings)->firstWhere('rule_id', 'pullPolicyNotAlways');
    expect($mutatedCheck['remediation'])->toBe('Set /spec/template/spec/containers/0/imagePullPolicy to "Always"');
});

test('polaris parser skips passing checks and resources with no pod result', function () {
    $report = json_encode([
        'Results' => [[
            'Name' => 'my-ingress',
            'Namespace' => 'default',
            'Kind' => 'Ingress',
            'Results' => [
                'tlsSettingsMissing' => ['ID' => 'tlsSettingsMissing', 'Message' => 'Ingress has TLS configured', 'Success' => true, 'Severity' => 'warning', 'Category' => 'Security'],
            ],
            'PodResult' => null,
        ]],
    ]);

    $findings = (new PolarisParser)->parse($report);

    expect($findings)->toBe([]);
});

test('trivy parser reads vulnerabilities and misconfigurations from noisy pod logs', function () {
    $report = json_encode([
        'ClusterName' => '',
        'Resources' => [
            [
                'Namespace' => 'default',
                'Kind' => 'Deployment',
                'Name' => 'my-app',
                'Results' => [
                    [
                        'Target' => 'my-app (debian 11)',
                        'Class' => 'os-pkgs',
                        'Vulnerabilities' => [
                            [
                                'VulnerabilityID' => 'CVE-2017-18018',
                                'PkgName' => 'coreutils',
                                'Title' => 'coreutils: race condition vulnerability',
                                'Severity' => 'HIGH',
                                'Description' => 'A race condition in chown-core.c',
                                'FixedVersion' => '8.32-4+b2',
                            ],
                        ],
                        'Misconfigurations' => [
                            [
                                'ID' => 'KSV012',
                                'Title' => 'Runs as root user',
                                'Severity' => 'MEDIUM',
                                'Description' => 'Container should not run as root',
                                'Resolution' => 'Set runAsNonRoot to true',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    // `trivy k8s` prints its own log lines before the JSON report.
    $raw = "time=\"2026-07-17T23:04:03Z\" level=info msg=\"Need to update the checks bundle\"\n".$report;

    $findings = (new TrivyParser)->parse($raw);

    expect($findings)->toHaveCount(2);

    $vuln = collect($findings)->firstWhere('rule_id', 'CVE-2017-18018');
    expect($vuln['severity'])->toBe('high')
        ->and($vuln['resource_type'])->toBe('Deployment')
        ->and($vuln['resource_name'])->toBe('default/my-app')
        ->and($vuln['description'])->toBe('A race condition in chown-core.c')
        ->and($vuln['remediation'])->toBe('Upgrade coreutils to 8.32-4+b2')
        ->and($vuln['metadata'])->toBe([
            'package' => 'coreutils',
            'fixed_version' => '8.32-4+b2',
        ]);

    $misconfig = collect($findings)->firstWhere('rule_id', 'KSV012');
    expect($misconfig['severity'])->toBe('medium')
        ->and($misconfig['category'])->toBe('misconfiguration')
        ->and($misconfig['resource_type'])->toBe('Deployment')
        ->and($misconfig['resource_name'])->toBe('default/my-app')
        ->and($misconfig['description'])->toBe('Container should not run as root')
        ->and($misconfig['remediation'])->toBe('Set runAsNonRoot to true')
        ->and($misconfig['metadata'])->toBeNull();
});

test('trivy parser still reads findings when only a single resource object was recovered', function () {
    // Simulates a report so large that Kubernetes log rotation cut off the
    // beginning (including the top-level "Resources" envelope), leaving only
    // the last complete Resource object.
    $resource = json_encode([
        'Namespace' => 'default',
        'Kind' => 'Pod',
        'Name' => 'my-pod',
        'Results' => [
            [
                'Target' => 'my-pod',
                'Vulnerabilities' => [
                    ['VulnerabilityID' => 'CVE-2024-0001', 'Severity' => 'CRITICAL'],
                ],
            ],
        ],
    ]);

    $findings = (new TrivyParser)->parse($resource);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['rule_id'])->toBe('CVE-2024-0001')
        ->and($findings[0]['severity'])->toBe('critical');
});

test('parser factory resolves a parser for every registered tool', function () {
    foreach (SecurityTool::all() as $tool) {
        expect(ParserFactory::make($tool))->toBeInstanceOf(\App\Support\Security\Parsers\Parser::class);
    }
});

test('parser factory throws for unknown tool', function () {
    expect(fn () => ParserFactory::make('not-a-tool'))->toThrow(InvalidArgumentException::class);
});
