<?php

use App\Enums\TaskStatus;
use App\Organization;
use App\Services\Tasks\TaskDispatcher;
use App\Task;
use App\TaskEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\TestSupports;

/**
 * TaskDispatcher::watch(): a task that has stopped getting anywhere logs an
 * error, once for each reason.
 */
beforeEach(function () {
    (new TestSupports)->seed();

    config(['tasks.alerts' => ['blocked_after' => 60, 'stalled_after' => 360, 'waiting_after' => 1440, 'requeued_times' => 3]]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function watchedTask(string $status, array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'system',
        'action_slug' => 'dummy_action',
        'task_group' => 31,
        'status' => $status,
        'description' => 'Watched task',
    ], $attributes));
    $task->save();

    return $task;
}

function ageTask(Task $task, int $minutes): void
{
    DB::table('tasks')->where('id', $task->id)->update([
        'updated_at' => now()->subMinutes($minutes),
        'created_at' => now()->subMinutes($minutes),
    ]);
}

function alertedFor(Task $task): array
{
    return $task->events()->where('type', 'alerted')->get()->map(fn (TaskEvent $event) => $event->context['condition'])->all();
}

it('reports a task that has been blocked too long, once', function () {
    Log::spy();
    $task = watchedTask('blocked', ['blocked_from' => 'pending', 'error_message' => 'A task it needs has failed']);
    ageTask($task, 61);

    app(TaskDispatcher::class)->watch();
    app(TaskDispatcher::class)->watch();

    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => str_contains($message, "Task #{$task->id}")
        && str_contains($message, 'blocked for over 60 minutes')
        && str_contains($message, 'A task it needs has failed')
        && $context['condition'] === 'blocked'
        && $context['task_id'] === $task->id);
    expect(alertedFor($task))->toBe(['blocked']);
});

it('leaves a task alone until it passes the limit', function () {
    Log::spy();
    $task = watchedTask('blocked', ['blocked_from' => 'pending']);
    ageTask($task, 59);

    app(TaskDispatcher::class)->watch();

    Log::shouldNotHaveReceived('error');
    expect(alertedFor($task))->toBe([]);

    // And reports it once it does
    Carbon::setTestNow(now()->addMinutes(2));
    app(TaskDispatcher::class)->watch();

    Log::shouldHaveReceived('error')->once();
});

it('reports a task in progress that nothing has changed in for hours, not one being run or with a deadline', function () {
    Log::spy();
    $stalled = watchedTask('in_progress');
    $running = watchedTask('in_progress', ['claimed_at' => now()]);
    $with_deadline = watchedTask('in_progress', ['deadline_at' => now()->addDay()]);
    $recent = watchedTask('in_progress');
    foreach ([$stalled, $running, $with_deadline] as $task) {
        ageTask($task, 361);
    }
    ageTask($recent, 10);

    app(TaskDispatcher::class)->watch();

    expect(alertedFor($stalled))->toBe(['stalled']);
    expect(alertedFor($running))->toBe([]);
    expect(alertedFor($with_deadline))->toBe([]);
    expect(alertedFor($recent))->toBe([]);
    Log::shouldHaveReceived('error')->once();
});

it('reports a task that has waited a day and never started, not one that has run before', function () {
    Log::spy();
    $waiting = watchedTask('pending', ['error_message' => 'Waiting for other tasks to complete']);
    $retried = watchedTask('pending', ['attempts' => 2]);
    ageTask($waiting, 1441);
    ageTask($retried, 1441);

    app(TaskDispatcher::class)->watch();

    expect(alertedFor($waiting))->toBe(['waiting']);
    expect(alertedFor($retried))->toBe([]);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, 'Waiting for other tasks to complete'));
});

it('reports a task whose queued job has not started several times: the workers are probably down', function () {
    Log::spy();
    $task = watchedTask('ready');
    $other = watchedTask('ready');
    foreach (range(1, 3) as $time) {
        TaskEvent::record($task, 'requeued');
    }
    TaskEvent::record($other, 'requeued');

    app(TaskDispatcher::class)->watch();

    expect(alertedFor($task))->toBe(['requeued']);
    expect(alertedFor($other))->toBe([]);
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, 'are the queue workers running'));
});

it('reports a task for each reason it is stuck, each once', function () {
    Log::spy();
    // A blocked task can't also be "requeued" (that only applies to tasks not yet started),
    // so use a pending one that has both waited a day and had its job requeued
    $task = watchedTask('pending');
    ageTask($task, 1441);
    foreach (range(1, 3) as $time) {
        TaskEvent::record($task, 'requeued');
    }

    app(TaskDispatcher::class)->watch();
    app(TaskDispatcher::class)->watch();

    expect(alertedFor($task))->toEqualCanonicalizing(['waiting', 'requeued']);
    Log::shouldHaveReceived('error')->twice();
});

it('does not report tasks that are finished, failed or cancelled', function (string $status) {
    Log::spy();
    $task = watchedTask($status);
    ageTask($task, 5000);
    foreach (range(1, 3) as $time) {
        TaskEvent::record($task, 'requeued');
    }

    app(TaskDispatcher::class)->watch();

    expect(alertedFor($task))->toBe([]);
    Log::shouldNotHaveReceived('error');
})->with(['complete', 'failed', 'cancelled']);

it('uses the limits it is configured with', function () {
    Log::spy();
    config(['tasks.alerts.blocked_after' => 5]);
    $task = watchedTask('blocked', ['blocked_from' => 'pending']);
    ageTask($task, 6);

    app(TaskDispatcher::class)->watch();

    expect(alertedFor($task))->toBe(['blocked']);
});

it('watches on every scheduled pass', function () {
    Log::spy();
    $task = watchedTask('blocked', ['blocked_from' => 'pending']);
    ageTask($task, 61);
    // A dependency that failed, so the pass leaves it blocked
    $dependency = watchedTask('failed');
    $task->dependOn($dependency);

    app(TaskDispatcher::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::BLOCKED);
    expect(alertedFor($task))->toBe(['blocked']);
});
