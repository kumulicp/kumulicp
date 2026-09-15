<?php

use App\AppInstance;
use App\Console\Calls\DeactivatedAppInstanceCheck;
use App\Organization;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * Covers the *other* deactivation path -- the one an end user actually hits.
 * Account\Applications::destroy() doesn't call any Apps\* Action at all: it
 * just stamps deactivate_at/status=deactivating, and DeactivatedAppInstanceCheck
 * (a scheduled sweep) later turns overdue instances into a real
 * ApplicationDelete. reactivate() is the undo button for the grace period.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
    setupBillingDriver('fake');
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function softDeleteTestInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);

    return test()->runActivate($plan, $org, $app);
}

it('marks an active app instance for deactivation without deleting it yet', function () {
    // The test-support billing fake used here (setupBillingDriver('fake') in
    // beforeEach) reports isBillable()=true with periodEnds()=null -- a
    // combination destroy() used to crash on (Call to a member function
    // format() on null) because it left deactivate_at null and then
    // unconditionally formatted it for the flash message. This is the
    // regression coverage for that path.
    $instance = softDeleteTestInstance();
    $user = User::where('username', 'demo')->firstOrFail();

    $this->actingAs($user)
        ->delete("/apps/{$instance->id}")
        ->assertRedirect();

    $instance->refresh();

    expect($instance->status)->toBe('deactivating');
    expect($instance->deactivate_at)->not->toBeNull();
    expect($instance->deactivate_at->isToday())->toBeTrue();
});

it('denies destroying an app instance belonging to another organization', function () {
    $instance = softDeleteTestInstance();
    $user = User::where('username', 'demo')->firstOrFail();

    $otherOrg = Organization::factory()->create(['slug' => 'other-org']);
    $instance->organization_id = $otherOrg->id;
    $instance->save();

    $this->actingAs($user)
        ->delete("/apps/{$instance->id}")
        ->assertForbidden();

    $instance->refresh();
    expect($instance->status)->toBe('active');
});

it('denies destroying an app instance that is not active', function () {
    $instance = softDeleteTestInstance();
    $instance->status = 'updating';
    $instance->save();
    $user = User::where('username', 'demo')->firstOrFail();

    $this->actingAs($user)
        ->delete("/apps/{$instance->id}")
        ->assertForbidden();
});

it('reactivates an app instance pending deactivation', function () {
    $instance = softDeleteTestInstance();
    $user = User::where('username', 'demo')->firstOrFail();

    $this->actingAs($user)->delete("/apps/{$instance->id}")->assertRedirect();
    $instance->refresh();
    expect($instance->status)->toBe('deactivating');

    $this->actingAs($user)
        ->post("/apps/{$instance->id}/reactivate")
        ->assertRedirect();

    $instance->refresh();
    expect($instance->status)->toBe('active');
    expect($instance->deactivate_at)->toBeNull();
});

it('denies reactivating an app instance that is not pending deactivation', function () {
    $instance = softDeleteTestInstance();
    $user = User::where('username', 'demo')->firstOrFail();

    $this->actingAs($user)
        ->post("/apps/{$instance->id}/reactivate")
        ->assertForbidden();
});

it('deletes app instances once their deactivate_at grace period has passed', function () {
    $instance = softDeleteTestInstance();
    $instanceId = $instance->id;
    $instance->status = 'deactivating';
    $instance->deactivate_at = now()->subDay();
    $instance->save();

    (new DeactivatedAppInstanceCheck)();

    $task = Task::where('app_instance_id', $instanceId)->where('action_slug', 'application_delete')->first();
    expect($task)->not->toBeNull();

    // ApplicationDelete is long_running -- with QUEUE_LONG_CONNECTION unset
    // in tests the auto-dispatch inside Action::execute() already ran it
    // synchronously, so this mirrors the shared trait's runDelete() pattern.
    Action::run($task);
    $task->refresh();
    Action::complete($task);

    expect(AppInstance::find($instanceId))->toBeNull();
});

it('does not touch app instances whose grace period has not passed yet', function () {
    $instance = softDeleteTestInstance();
    $instance->status = 'deactivating';
    $instance->deactivate_at = now()->addDay();
    $instance->save();

    (new DeactivatedAppInstanceCheck)();

    $instance->refresh();
    expect($instance->status)->toBe('deactivating');
    expect(Task::where('app_instance_id', $instance->id)->where('action_slug', 'application_delete')->exists())->toBeFalse();
});

it('does not let one blocked shared-app hub stop the rest of the sweep', function () {
    $hub = softDeleteTestInstance();
    $hub->status = 'deactivating';
    $hub->deactivate_at = now()->subDay();
    $hub->save();

    // Still has an active child -- ApplicationDelete's constructor throws
    // for this, and DeactivatedAppInstanceCheck must swallow it and move on.
    $this->makeChildInstance($hub, 'hub-child');

    // A second, unrelated instance -- built by hand rather than via a
    // second softDeleteTestInstance() call, since that reseeds fixed-id
    // rows (e.g. the server with id=1) that only tolerate a single insert
    // per test.
    $otherInstance = $this->makeChildInstance($hub, 'other-instance');
    $otherInstance->parent_id = null;
    $otherInstance->status = 'deactivating';
    $otherInstance->deactivate_at = now()->subDay();
    $otherInstance->save();

    (new DeactivatedAppInstanceCheck)();

    $hub->refresh();
    expect($hub->status)->toBe('deactivating');
    expect(Task::where('app_instance_id', $hub->id)->where('action_slug', 'application_delete')->exists())->toBeFalse();

    expect(Task::where('app_instance_id', $otherInstance->id)->where('action_slug', 'application_delete')->exists())->toBeTrue();
});
