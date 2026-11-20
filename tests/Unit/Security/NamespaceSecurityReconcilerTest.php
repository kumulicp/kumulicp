<?php

use App\Support\Security\NamespaceSecurityReconciler as Reconciler;
use App\Support\Security\SecurityTier;

function reconcilerDesiredFor(string $tier): array
{
    return ['tier' => $tier, 'labels' => SecurityTier::presets()[$tier]->podSecurityLabels()];
}

it('changes nothing for a namespace KumuliCP does not manage', function () {
    expect(Reconciler::changes(null, ['pod-security.kubernetes.io/enforce' => 'baseline'], []))
        ->toBe(['labels' => [], 'annotations' => []]);
});

it('adds the labels and ownership annotation to a bare namespace', function () {
    $desired = reconcilerDesiredFor('observe');

    expect(Reconciler::changes($desired, [], []))->toBe([
        'labels' => $desired['labels'],
        'annotations' => [Reconciler::ANNOTATION => 'observe'],
    ]);
});

it('is empty once the namespace already matches', function () {
    $desired = reconcilerDesiredFor('observe');

    expect(Reconciler::isEmpty(Reconciler::changes($desired, $desired['labels'], [Reconciler::ANNOTATION => 'observe'])))->toBeTrue();
});

it('adds enforce when a plan moves to a stricter tier', function () {
    $changes = Reconciler::changes(reconcilerDesiredFor('baseline'), reconcilerDesiredFor('observe')['labels'], [Reconciler::ANNOTATION => 'observe']);

    expect($changes['labels'])->toBe(['pod-security.kubernetes.io/enforce' => 'baseline'])
        ->and($changes['annotations'])->toBe([Reconciler::ANNOTATION => 'baseline']);
});

it('removes labels the new tier no longer wants', function () {
    $changes = Reconciler::changes(reconcilerDesiredFor('observe'), reconcilerDesiredFor('baseline')['labels'], [Reconciler::ANNOTATION => 'baseline']);

    expect($changes['labels'])->toBe(['pod-security.kubernetes.io/enforce' => null]);
});

it('leaves labels alone on a namespace it never managed when the tier is none', function () {
    $none = ['tier' => 'none', 'labels' => []];

    expect(Reconciler::changes($none, ['pod-security.kubernetes.io/enforce' => 'baseline'], []))
        ->toBe(['labels' => [], 'annotations' => []]);
});

it('removes only its own pod security labels when a managed namespace moves to none', function () {
    $none = ['tier' => 'none', 'labels' => []];
    $labels = reconcilerDesiredFor('observe')['labels'] + ['team' => 'x'];

    expect(Reconciler::changes($none, $labels, [Reconciler::ANNOTATION => 'observe']))->toBe([
        'labels' => [
            'pod-security.kubernetes.io/warn' => null,
            'pod-security.kubernetes.io/audit' => null,
        ],
        'annotations' => [Reconciler::ANNOTATION => null],
    ]);
});

it('provides no labels at creation unless a tier is active', function () {
    $empty = ['labels' => [], 'annotations' => []];

    expect(Reconciler::metadataForCreate(null))->toBe($empty)
        ->and(Reconciler::metadataForCreate(['tier' => 'none', 'labels' => []]))->toBe($empty)
        ->and(Reconciler::metadataForCreate(reconcilerDesiredFor('observe'))['annotations'])->toBe([Reconciler::ANNOTATION => 'observe']);
});
