<?php

use App\Actions\Apps\ApplicationUpgrade;
use App\Organization;
use App\Support\Facades\Action;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * Covers ApplicationUpgrade::complete()'s recovery for a release stuck
 * pending because the process running `helm upgrade --wait` was killed
 * mid-operation (e.g. the long queue's container restarted).
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function upgradeStuckReleaseInstance()
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);

    return test()->runActivate($plan, $org, $app);
}

it('does not recover a release that only just started pending', function () {
    $instance = upgradeStuckReleaseInstance();

    $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));
    Action::run($task);

    FakeServerManager::markPending($instance->id);

    Action::complete($task);
    $task->refresh();

    expect($task->status)->not->toBe('complete');
    expect($task->getValue('helm_pending_since'))->not->toBeNull();
    expect(FakeServerManager::$recover_stuck_release_calls)->toBe(0);
});

it('recovers a release stuck pending past the long queue timeout', function () {
    $instance = upgradeStuckReleaseInstance();

    $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));
    Action::run($task);

    FakeServerManager::markPending($instance->id);

    // First poll: just records when the pending streak started.
    Action::complete($task);
    $task->refresh();

    // Backdate it past the long queue's retry_after threshold.
    $threshold = config('queue.connections.database-long.retry_after', 960);
    $custom_values = $task->custom_values;
    $custom_values['helm_pending_since'] = now()->subSeconds($threshold + 10)->toISOString();
    $task->custom_values = $custom_values;
    $task->save();

    Action::complete($task);
    $task->refresh();

    expect(FakeServerManager::$recover_stuck_release_calls)->toBe(1);
    expect($task->status)->toBe('pending');
    expect($task->getValue('helm_pending_since'))->toBeNull();
});

it('clears the pending timer once the release becomes active', function () {
    $instance = upgradeStuckReleaseInstance();

    $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));
    Action::run($task);

    FakeServerManager::markPending($instance->id);
    Action::complete($task);
    $task->refresh();

    expect($task->getValue('helm_pending_since'))->not->toBeNull();

    FakeServerManager::clearPending($instance->id);
    Action::complete($task);
    $task->refresh();

    expect($task->status)->toBe('complete');
});
