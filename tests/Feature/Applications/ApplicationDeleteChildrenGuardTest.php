<?php

use App\Actions\Apps\ApplicationDelete;
use App\Organization;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * ApplicationDelete's constructor guards a shared-app hub (or any
 * type-level parent) against being deleted while children still point their
 * parent_id at it -- deleting it out from under them would orphan the FK.
 * The guard only blocks children considered "still live"
 * (AppInstance::scopeNotDeactivated: not 'deactivated' AND not
 * 'deactivating') -- a child already mid-cascade-deactivation does not
 * block the hub's own deletion.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function childGuardTestHub()
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);

    return test()->runActivate($plan, $org, $app);
}

it('refuses to delete a hub that still has an active child', function () {
    $hub = childGuardTestHub();
    $this->makeChildInstance($hub, 'active-child', 'active');

    expect(fn () => new ApplicationDelete($hub))
        ->toThrow(Exception::class, __('messages.exception.shared_app_has_children', ['app' => $hub->label]));
});

it('refuses to delete a hub with a child still activating', function () {
    $hub = childGuardTestHub();
    $this->makeChildInstance($hub, 'activating-child', 'activating');

    expect(fn () => new ApplicationDelete($hub))->toThrow(Exception::class);
});

it('allows deleting a hub whose only child is already deactivating', function () {
    $hub = childGuardTestHub();
    $this->makeChildInstance($hub, 'deactivating-child', 'deactivating');

    expect(fn () => new ApplicationDelete($hub))->not->toThrow(Exception::class);
    $hub->refresh();
    expect($hub->status)->toBe('deleting');
});

it('allows deleting a hub whose only child is already deactivated', function () {
    $hub = childGuardTestHub();
    $this->makeChildInstance($hub, 'deactivated-child', 'deactivated');

    expect(fn () => new ApplicationDelete($hub))->not->toThrow(Exception::class);
    $hub->refresh();
    expect($hub->status)->toBe('deleting');
});

it('allows deleting a hub with no children at all', function () {
    $hub = childGuardTestHub();

    expect(fn () => new ApplicationDelete($hub))->not->toThrow(Exception::class);
});
