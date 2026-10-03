<?php

use App\Organization;
use App\Plan;
use App\Server;
use App\Support\Facades\Settings;
use App\Support\Security\NamespaceSecurityPolicy;
use App\Support\Security\SecurityTier;

it('defaults a server to off, and treats an unknown mode as off', function () {
    expect(NamespaceSecurityPolicy::mode(Server::factory()->make(['settings' => []])))->toBe('off')
        ->and(NamespaceSecurityPolicy::mode(Server::factory()->make(['settings' => ['security_mode' => 'nonsense']])))->toBe('off')
        ->and(NamespaceSecurityPolicy::mode(Server::factory()->make(['settings' => ['security_mode' => 'managed']])))->toBe('managed');
});

it('lists the built-in tiers', function () {
    expect(array_keys(NamespaceSecurityPolicy::tiers()))->toBe(['none', 'observe', 'baseline', 'restricted']);
});

it('adds custom tiers from system settings but never lets them replace a preset', function () {
    Settings::update(NamespaceSecurityPolicy::TIERS_SETTING, json_encode([
        'strict-ish' => ['label' => 'Strict-ish', 'enforce' => 'baseline', 'warn' => 'restricted'],
        'baseline' => ['enforce' => 'privileged'],
        'Bad Key!' => ['enforce' => 'baseline'],
    ]));

    $tiers = NamespaceSecurityPolicy::tiers();

    expect($tiers)->toHaveKey('strict-ish')
        ->and($tiers['strict-ish']->enforce)->toBe('baseline')
        ->and($tiers['baseline']->enforce)->toBe('baseline')
        ->and($tiers)->not->toHaveKey('Bad Key!')
        ->and(array_keys(NamespaceSecurityPolicy::customTiers()))->toBe(['strict-ish']);
});

it('ignores a corrupt custom tier setting', function () {
    Settings::update(NamespaceSecurityPolicy::TIERS_SETTING, 'not json');

    expect(array_keys(NamespaceSecurityPolicy::tiers()))->toBe(['none', 'observe', 'baseline', 'restricted']);
});

it('resolves an organization to its base plan\'s tier, defaulting to none', function () {
    $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'observe']]]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);
    $without_tier = Organization::factory()->create(['plan_id' => Plan::factory()->create(['settings' => []])->id]);

    expect(NamespaceSecurityPolicy::tierFor($organization)->key)->toBe('observe')
        ->and(NamespaceSecurityPolicy::tierFor($without_tier)->key)->toBe(SecurityTier::NONE);
});

it('falls back to none when the plan names a tier that no longer exists', function () {
    $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'removed']]]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);

    expect(NamespaceSecurityPolicy::tierFor($organization)->key)->toBe(SecurityTier::NONE);
});

it('only manages a namespace when the server mode is managed', function () {
    $plan = Plan::factory()->create(['settings' => ['security' => ['tier' => 'observe']]]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);

    $off = Server::factory()->make(['settings' => []]);
    $observe = Server::factory()->make(['settings' => ['security_mode' => 'observe']]);
    $managed = Server::factory()->make(['settings' => ['security_mode' => 'managed']]);

    expect(NamespaceSecurityPolicy::desired($organization, $off))->toBeNull()
        ->and(NamespaceSecurityPolicy::desired($organization, $observe))->toBeNull()
        ->and(NamespaceSecurityPolicy::desired($organization, $managed))->toBe([
            'tier' => 'observe',
            'labels' => [
                'pod-security.kubernetes.io/warn' => 'restricted',
                'pod-security.kubernetes.io/audit' => 'restricted',
            ],
        ]);
});
