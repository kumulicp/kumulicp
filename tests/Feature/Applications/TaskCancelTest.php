<?php

use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppVersion;
use App\Organization;
use App\Services\Tasks\TaskOperator;
use App\Support\Facades\Action;
use App\Task;
use App\TaskEvent;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * Cancelling an upgrade or a delete puts the app back as far as it can: those
 * actions mark the app updating/deleting the moment their task is made.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

// What a worker does when it takes the task up
function markRun(Task $task): void
{
    $task->attempts = 1;
    $task->save();
    TaskEvent::record($task, 'started');
}

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function cancellableInstance()
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    return test()->runActivate($plan, Organization::find(1), $app);
}

it('puts the app back on its version and makes it active when an upgrade that has not started is cancelled', function () {
    $instance = cancellableInstance();
    $old_version = $instance->version_id;
    $new_version = AppVersion::factory()->create(['application_id' => $instance->application_id]);
    Queue::fake();
    $task = Action::execute(new ApplicationUpgrade($instance, $new_version));

    expect($instance->fresh()->status)->toBe('updating');
    expect($instance->fresh()->version_id)->toBe($new_version->id);
    expect($task->getValue('previous_version_id'))->toBe($old_version);

    app(TaskOperator::class)->cancel($task);

    expect($instance->fresh()->status)->toBe('active');
    expect($instance->fresh()->version_id)->toBe($old_version);
    expect($task->events()->where('type', 'cancelled')->sole()->message)->toBeNull();
});

it('keeps the version it was first on when an upgrade is retried before it is cancelled', function () {
    $instance = cancellableInstance();
    $old_version = $instance->version_id;
    $new_version = AppVersion::factory()->create(['application_id' => $instance->application_id]);
    Queue::fake();
    $task = Action::execute(new ApplicationUpgrade($instance, $new_version));

    Action::retry($task);
    expect($task->fresh()->getValue('previous_version_id'))->toBe($old_version);

    app(TaskOperator::class)->cancel($task->fresh());

    expect($instance->fresh()->version_id)->toBe($old_version);
});

it('makes the app active but says it may be part way through when an upgrade that had started is cancelled', function () {
    $instance = cancellableInstance();
    $new_version = AppVersion::factory()->create(['application_id' => $instance->application_id]);
    Queue::fake();
    $task = Action::execute(new ApplicationUpgrade($instance, $new_version));
    markRun($task);

    app(TaskOperator::class)->cancel($task->fresh());

    expect($instance->fresh()->status)->toBe('active');
    // Not put back on the old version: the chart may already be on the new one
    expect($instance->fresh()->version_id)->toBe($new_version->id);
    expect($task->events()->where('type', 'cancelled')->sole()->message)->toBe(__('messages.action.cancelled_upgrade_started'));
});

it('leaves an app alone that is not being upgraded any more', function () {
    $instance = cancellableInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));
    $instance->status = 'active';
    $instance->save();

    app(TaskOperator::class)->cancel($task);

    expect($instance->fresh()->status)->toBe('active');
});

it('makes the app active again when a delete that has not started is cancelled', function () {
    $instance = cancellableInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationDelete($instance));
    expect($instance->fresh()->status)->toBe('deleting');

    app(TaskOperator::class)->cancel($task);

    expect($instance->fresh()->status)->toBe('active');
    expect($task->events()->where('type', 'cancelled')->sole()->message)->toBeNull();
});

it('leaves an app deleting, and says why, when a delete that had started is cancelled', function () {
    $instance = cancellableInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationDelete($instance));
    markRun($task);

    app(TaskOperator::class)->cancel($task->fresh());

    expect($instance->fresh()->status)->toBe('deleting');
    expect($task->events()->where('type', 'cancelled')->sole()->message)->toBe(__('messages.action.cancelled_delete_started'));
});
