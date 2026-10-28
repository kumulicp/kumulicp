<?php

use App\Actions\Action;
use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Organization;
use App\Services\Tasks\TaskDispatcher;
use App\Task;
use App\TaskEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * TaskDispatcher: the only way a task gets onto the queue, and the ordered
 * pass that moves every task along.
 */
class DispatcherRecorder extends Action
{
    /** @var array<int, int> */
    public static array $completed = [];

    /** @var array<int, int> */
    public static array $throw_for = [];

    public static function complete(Task $task)
    {
        self::$completed[] = $task->id;

        if (in_array($task->id, self::$throw_for)) {
            throw new RuntimeException('cannot poll');
        }
    }
}

beforeEach(function () {
    (new TestSupports)->seed();

    DispatcherRecorder::$completed = [];
    DispatcherRecorder::$throw_for = [];
    app('actions')->register('dispatcher.recorder', DispatcherRecorder::class);
});

function dispatcherTask(array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'dispatcher',
        'action_slug' => 'recorder',
        'task_group' => 9,
        'status' => 'pending',
        'description' => 'Dispatcher test task',
    ], $attributes));
    $task->save();

    return $task;
}

it('queues a pending or ready task once', function () {
    Queue::fake();
    $pending = dispatcherTask();
    $ready = dispatcherTask(['status' => 'ready', 'error_message' => 'stale']);

    $dispatcher = app(TaskDispatcher::class);

    expect($dispatcher->queue($pending))->toBeTrue();
    expect($dispatcher->queue($pending))->toBeFalse();
    expect($dispatcher->queue($ready))->toBeTrue();

    Queue::assertPushed(RunAction::class, 2);
    expect($pending->fresh()->status)->toBe(TaskStatus::QUEUED);
    expect($pending->fresh()->queued_at)->not->toBeNull();
    expect($ready->fresh()->error_message)->toBeNull();
    expect($pending->events()->pluck('type')->all())->toBe(['queued']);
});

it('does not queue a task that is finished or waiting out a retry delay', function () {
    Queue::fake();
    $dispatcher = app(TaskDispatcher::class);

    expect($dispatcher->queue(dispatcherTask(['status' => 'complete'])))->toBeFalse();
    expect($dispatcher->queue(dispatcherTask(['status' => 'failed'])))->toBeFalse();
    expect($dispatcher->queue(dispatcherTask(['next_attempt_at' => now()->addMinutes(5)])))->toBeFalse();

    Queue::assertNothingPushed();
});

it('dispatches a task created in progress without moving it, unless something is running it', function () {
    Queue::fake();
    $dispatcher = app(TaskDispatcher::class);
    $waiting = dispatcherTask(['status' => 'in_progress']);
    $running = dispatcherTask(['status' => 'in_progress', 'claimed_at' => now()]);

    expect($dispatcher->queue($waiting))->toBeTrue();
    expect($dispatcher->queue($running))->toBeFalse();

    Queue::assertPushed(RunAction::class, 1);
    expect($waiting->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
});

it('promotes pending tasks once their retry delay has passed', function () {
    $due = dispatcherTask(['next_attempt_at' => now()->subMinute()]);
    $waiting = dispatcherTask(['next_attempt_at' => now()->addMinutes(5)]);
    $fresh = dispatcherTask();

    app(TaskDispatcher::class)->promote();

    expect($due->fresh()->status)->toBe(TaskStatus::READY);
    expect($fresh->fresh()->status)->toBe(TaskStatus::READY);
    expect($waiting->fresh()->status)->toBe(TaskStatus::PENDING);
});

it('moves a task from pending to the queue in a single tick', function () {
    Queue::fake();
    $task = dispatcherTask();

    app(TaskDispatcher::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::QUEUED);
    Queue::assertPushed(RunAction::class, 1);
});

it('polls in-progress tasks nothing is running on a tick', function () {
    Queue::fake();
    $idle = dispatcherTask(['status' => 'in_progress']);
    $running = dispatcherTask(['status' => 'in_progress', 'claimed_at' => now()]);

    app(TaskDispatcher::class)->tick();

    expect(DispatcherRecorder::$completed)->toBe([$idle->id]);
});

it('keeps going when one task cannot be polled', function () {
    $broken = dispatcherTask(['status' => 'in_progress']);
    $other = dispatcherTask(['status' => 'in_progress']);
    DispatcherRecorder::$throw_for = [$broken->id];

    app(TaskDispatcher::class)->poll();

    expect(DispatcherRecorder::$completed)->toContain($broken->id)->toContain($other->id);
    expect($broken->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($broken->fresh()->error_code)->toBe('failed_complete');
    expect($broken->fresh()->error_message)->toBe('cannot poll');
});

it('writes an event when a lost job is queued again or a deadline passes', function () {
    $lost = dispatcherTask(['status' => 'queued', 'queued_at' => now()->subMinutes(Task::QUEUED_TIMEOUT_MINUTES + 1)]);
    $late = dispatcherTask(['status' => 'in_progress', 'deadline_at' => now()->subMinute()]);

    app(TaskDispatcher::class)->reap();

    expect($lost->events()->pluck('type')->all())->toBe(['requeued']);
    expect($late->events()->pluck('type')->all())->toBe(['deadline_exceeded']);
    expect($late->fresh()->status)->toBe(TaskStatus::FAILED);
});

it('removes a task\'s events with the task and never throws recording for a gone one', function () {
    $task = dispatcherTask();
    TaskEvent::record($task, 'queued');
    expect(TaskEvent::where('task_id', $task->id)->count())->toBe(1);

    DB::table('tasks')->where('id', $task->id)->delete();

    expect(TaskEvent::where('task_id', $task->id)->count())->toBe(0);
    expect(TaskEvent::record($task, 'queued'))->toBeNull();
});
