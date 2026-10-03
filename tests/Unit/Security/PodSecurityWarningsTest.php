<?php

use App\Support\Security\PodSecurityWarnings;

it('extracts warnings from helm and kubectl output', function () {
    $output = <<<'LOG'
    Release "nc" does not exist. Installing it now.
    W1003 12:00:00.123456       1 warnings.go:70] would violate PodSecurity "restricted:latest": allowPrivilegeEscalation != false (containers "nextcloud", "nextcloud-cron" must set securityContext.allowPrivilegeEscalation=false), runAsNonRoot != true (pod or containers "nextcloud" must set securityContext.runAsNonRoot=true)
    Warning: would violate PodSecurity "restricted:latest": seccompProfile (pod or container "redis" must set securityContext.seccompProfile.type to "RuntimeDefault" or "Localhost")
    STATUS: deployed
    LOG;

    $warnings = PodSecurityWarnings::parse($output);

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0]['profile'])->toBe('restricted:latest')
        ->and($warnings[0]['violations'])->toHaveCount(2)
        ->and($warnings[1]['violations'][0])->toStartWith('seccompProfile (pod or container "redis"');
});

it('keeps the commas inside a violation together', function () {
    $violations = PodSecurityWarnings::splitViolations('a != b (containers "x", "y" must set z), c (d)');

    expect($violations)->toBe(['a != b (containers "x", "y" must set z)', 'c (d)']);
});

it('de-duplicates repeated warnings', function () {
    $line = 'Warning: would violate PodSecurity "baseline:latest": hostPath volumes (volume "h")';

    expect(PodSecurityWarnings::parse("$line\n$line"))->toHaveCount(1);
});

it('returns nothing when there are no warnings', function () {
    expect(PodSecurityWarnings::parse("STATUS: deployed\n"))->toBe([])
        ->and(PodSecurityWarnings::parse(''))->toBe([]);
});
