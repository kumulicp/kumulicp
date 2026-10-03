<?php

use App\Support\Security\SecurityTier;

it('ships a none preset that is inactive and emits no labels', function () {
    $none = SecurityTier::presets()[SecurityTier::NONE];

    expect($none->isActive())->toBeFalse()
        ->and($none->podSecurityLabels())->toBe([]);
});

it('builds the pod security labels for each preset', function () {
    $presets = SecurityTier::presets();

    expect($presets['observe']->podSecurityLabels())->toBe([
        'pod-security.kubernetes.io/warn' => 'restricted',
        'pod-security.kubernetes.io/audit' => 'restricted',
    ])->and($presets['baseline']->podSecurityLabels())->toBe([
        'pod-security.kubernetes.io/enforce' => 'baseline',
        'pod-security.kubernetes.io/warn' => 'restricted',
        'pod-security.kubernetes.io/audit' => 'restricted',
    ])->and($presets['restricted']->podSecurityLabels()['pod-security.kubernetes.io/enforce'])->toBe('restricted');
});

it('only emits a -version label when the version is pinned', function () {
    $tier = SecurityTier::fromArray('pinned', ['warn' => 'baseline', 'warn_version' => 'v1.30']);

    expect($tier->podSecurityLabels())->toBe([
        'pod-security.kubernetes.io/warn' => 'baseline',
        'pod-security.kubernetes.io/warn-version' => 'v1.30',
    ]);
});

it('drops invalid levels and versions instead of failing', function () {
    $tier = SecurityTier::fromArray('x', [
        'enforce' => 'everything',
        'warn' => 'baseline',
        'audit_version' => '1.30',
        'label' => '',
    ]);

    expect($tier->enforce)->toBeNull()
        ->and($tier->warn)->toBe('baseline')
        ->and($tier->auditVersion)->toBe('latest')
        ->and($tier->label)->toBeNull();
});

it('round-trips through toArray()', function () {
    $tier = SecurityTier::fromArray('custom', ['label' => 'Custom', 'enforce' => 'baseline', 'enforce_version' => 'v1.31']);

    expect(SecurityTier::fromArray('custom', $tier->toArray())->podSecurityLabels())->toBe($tier->podSecurityLabels());
});
