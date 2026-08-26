<?php

use App\Organization;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization as OrganizationFacade;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| FastCacheService
|--------------------------------------------------------------------------
|
| FastCacheService scopes cached entries to the current organization via
| cache tags, which only taggable stores ("array", "redis", "memcached")
| support. The test environment's default (config('cache.default') =
| 'array' per phpunit.xml) is taggable, so the tests below that force
| config(['cache.default' => 'file']) exist specifically to exercise the
| non-taggable fallback path, which the rest of the suite never touches.
|
| These use OrganizationFacade::setOrganization() (the same pattern
| SubscriptionServiceTest uses) rather than switching the authenticated
| user, because OrganizationService only derives "the current organization"
| from Auth::user() once, at construction - switching actingAs() mid-test
| would not actually change what Organization::account() returns here.
*/

it('caches the closure result and does not recompute on the next call', function () {
    OrganizationFacade::setOrganization(Organization::factory()->create());

    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return 'computed-value';
    };

    expect(FastCache::retrieve('some-key', $compute))->toBe('computed-value');
    expect(FastCache::retrieve('some-key', $compute))->toBe('computed-value');
    expect($calls)->toBe(1);
});

it('scopes clear() to a single organization, leaving other organizations cached', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $callsA = 0;
    $callsB = 0;
    $valueA = function () use (&$callsA) {
        $callsA++;

        return 'org-a-value';
    };
    $valueB = function () use (&$callsB) {
        $callsB++;

        return 'org-b-value';
    };

    OrganizationFacade::setOrganization($orgA);
    FastCache::retrieve('shared-key', $valueA);

    OrganizationFacade::setOrganization($orgB);
    FastCache::retrieve('shared-key', $valueB);

    FastCache::clear('shared-key', $orgB);

    // Org B's entry was cleared, so it recomputes.
    FastCache::retrieve('shared-key', $valueB);
    expect($callsB)->toBe(2);

    // Org A's entry is untouched by org B's clear().
    OrganizationFacade::setOrganization($orgA);
    FastCache::retrieve('shared-key', $valueA);
    expect($callsA)->toBe(1);
});

it('logs a warning and skips invalidation when the cache store is not taggable', function () {
    config(['cache.default' => 'file']);
    Log::spy();

    OrganizationFacade::setOrganization(Organization::factory()->create());

    FastCache::clear('some-key');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'non-taggable'));
});

it('does not cache anything when the store is not taggable, but still returns the computed value', function () {
    config(['cache.default' => 'file']);

    OrganizationFacade::setOrganization(Organization::factory()->create());

    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return 'computed-value';
    };

    expect(FastCache::retrieve('some-key', $compute))->toBe('computed-value');
    expect(FastCache::retrieve('some-key', $compute))->toBe('computed-value');

    // Recomputed both times: nothing was cached without a taggable store.
    expect($calls)->toBe(2);
});
