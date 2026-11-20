<?php

use App\Support\Security\PreflightWarnings;
use App\Support\Security\SecurityTier;

$warnings = [
    'existing pods in namespace "demo" violate the new PodSecurity enforce level "baseline:latest"',
    'nextcloud-5d9f (and 1 other pod): allowPrivilegeEscalation != false (container "nextcloud" must set securityContext.allowPrivilegeEscalation=false), runAsNonRoot != true (pod or container "nextcloud" must set securityContext.runAsNonRoot=true)',
    'redis-0: seccompProfile (pod or container "redis" must set securityContext.seccompProfile.type to "RuntimeDefault" or "Localhost")',
    'something else entirely',
];

it('splits the headline from the pod groups', function () use ($warnings) {
    $parsed = PreflightWarnings::parse($warnings);

    expect($parsed['headline'])->toBe($warnings[0])
        ->and($parsed['pods'])->toHaveCount(2)
        ->and($parsed['pods'][0])->toMatchArray(['pod' => 'nextcloud-5d9f', 'others' => 1])
        ->and($parsed['pods'][0]['violations'])->toHaveCount(2)
        ->and($parsed['pods'][1])->toMatchArray(['pod' => 'redis-0', 'others' => 0])
        ->and($parsed['other'])->toBe(['something else entirely']);
});

it('counts the "and N other pods" in the pod total', function () use ($warnings) {
    expect(PreflightWarnings::podCount(PreflightWarnings::parse($warnings)))->toBe(3);
});

it('returns nothing for no warnings', function () {
    expect(PreflightWarnings::parse([]))->toBe(['headline' => null, 'pods' => [], 'other' => []]);
});

it('ranks levels from none up to restricted', function () {
    expect([SecurityTier::rank(null), SecurityTier::rank('privileged'), SecurityTier::rank('baseline'), SecurityTier::rank('restricted'), SecurityTier::rank('bogus')])
        ->toBe([-1, 0, 1, 2, -1]);
});

it('only counts a stricter enforce level as raising it', function () {
    $presets = SecurityTier::presets();

    expect($presets['baseline']->raisesEnforceFrom($presets['none']))->toBeTrue()
        ->and($presets['restricted']->raisesEnforceFrom($presets['baseline']))->toBeTrue()
        ->and($presets['baseline']->raisesEnforceFrom($presets['baseline']))->toBeFalse()
        ->and($presets['observe']->raisesEnforceFrom($presets['restricted']))->toBeFalse();
});
