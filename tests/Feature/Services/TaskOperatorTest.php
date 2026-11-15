<?php

use App\Actions\NativeAction;
use App\Enums\TaskStatus;
use App\Exceptions\Tasks\TaskOperationRefused;
use App\Organization;
use App\Services\Tasks\TaskDependencies;
use App\Services\Tasks\TaskDispatcher;
use App\Services\Tasks\TaskOperator;
use App\Task;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * What an admin can do to a task, and when: retry, cancel and delete follow the
 * task's state, and leave a mark in its history.
 */
class OperatorAction extends NativeAction
{
    /** @var array<int, int> */
    public static array $compensated = [];

    public static ?string $note = null;

    public static function compensate(Task $task): void
    {
        self::$compensated[] = $task->id;
    }

    public static function cancelled(Task $task): ?string
    {
        return self::$note;
    }
}

beforeEach(function () {
    (new TestSupports)->seed();

    OperatorAction::$compensated = [];
    OperatorAction::$note = null;
    app('actions')->register('operator.action', OperatorAction::class);
});

function operatorTask(string $status = 'pending', array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'operator',
        'action_slug' => 'action',
        'task_group' => 11,
        'status' => $status,
        'description' => 'Operator test task',
    ], $attributes));
    $task->save();

    return $task;
}

it('can say what an admin may do in each status', function () {
    $retryable = [];
    $cancellable = [];
    $deletable = [];

    foreach (TaskStatus::cases() as $status) {
        $status->isRetryable() && $retryable[] = $status->value;
        $status->isCancellable() && $cancellable[] = $status->value;
        $status->isDeletable() && $deletable[] = $status->value;
    }

    expect($retryable)->toBe(['failed', 'blocked', 'cancelled']);
    expect($cancellable)->toBe(['pending', 'ready', 'queued', 'in_progress', 'blocked']);
    expect($deletable)->toBe(['complete', 'failed', 'cancelled']);
});

it('retries a task that failed, is blocked or was cancelled, and records who', function (string $status) {
    Queue::fake();
    $task = operatorTask($status, ['error_message' => 'before', 'attempts' => 2]);
    $admin = User::find(1);

    app(TaskOperator::class)->retry($task, $admin);

    expect($task->fresh()->status)->not->toBe(TaskStatus::from($status));
    expect($task->fresh()->error_message)->not->toBe('before');
    $event = $task->events()->where('type', 'retried')->sole();
    expect($event->context)->toBe(['by' => $admin->getAuthIdentifier()]);
    expect($event->from_status)->toBe(TaskStatus::from($status));
})->with(['failed', 'blocked', 'cancelled']);

it('refuses to retry a task that is running, waiting or done', function (string $status) {
    $task = operatorTask($status);

    expect(fn () => app(TaskOperator::class)->retry($task))
        ->toThrow(TaskOperationRefused::class, __('admin.tasks.not_retryable'));
    expect($task->fresh()->status)->toBe(TaskStatus::from($status));
    expect($task->events()->count())->toBe(0);
})->with(['pending', 'ready', 'queued', 'in_progress', 'complete']);

it('cancels a task: it stops, is rolled back, and says who did it', function (string $status) {
    OperatorAction::$note = 'The app was put back.';
    $task = operatorTask($status, ['queued_at' => now(), 'next_attempt_at' => now()->addHour(), 'blocked_from' => $status === 'blocked' ? 'pending' : null]);
    $admin = User::find(1);

    app(TaskOperator::class)->cancel($task, $admin);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::CANCELLED);
    expect($fresh->error_code)->toBe('cancelled');
    expect($fresh->error_message)->toBe(__('admin.tasks.cancelled_by_admin'));
    expect($fresh->queued_at)->toBeNull();
    expect($fresh->next_attempt_at)->toBeNull();
    expect($fresh->blocked_from)->toBeNull();
    expect(OperatorAction::$compensated)->toBe([$task->id]);

    $event = $task->events()->where('type', 'cancelled')->sole();
    expect($event->message)->toBe('The app was put back.');
    expect($event->context)->toBe(['by' => $admin->getAuthIdentifier()]);
    expect($event->from_status)->toBe(TaskStatus::from($status));
})->with(['pending', 'ready', 'queued', 'in_progress', 'blocked']);

it('refuses to cancel a task that is over', function (string $status) {
    $task = operatorTask($status);

    expect(fn () => app(TaskOperator::class)->cancel($task))
        ->toThrow(TaskOperationRefused::class, __('admin.tasks.not_cancellable'));
    expect(OperatorAction::$compensated)->toBe([]);
})->with(['complete', 'failed', 'cancelled']);

it('refuses to cancel a task a worker is running right now', function () {
    $task = operatorTask('in_progress', ['claimed_at' => now()]);

    expect(fn () => app(TaskOperator::class)->cancel($task))
        ->toThrow(TaskOperationRefused::class, __('admin.tasks.running_now'));

    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect(OperatorAction::$compensated)->toBe([]);
});

it('cancels a task whose worker is long gone', function () {
    $task = operatorTask('in_progress', ['claimed_at' => now()->subSeconds(Task::CLAIM_LEASE_SECONDS + 60)]);

    app(TaskOperator::class)->cancel($task);

    expect($task->fresh()->status)->toBe(TaskStatus::CANCELLED);
});

it('still cancels when rolling back or tidying up goes wrong, and says so', function () {
    app('actions')->register('operator.broken', BrokenOperatorAction::class);
    $broken = operatorTask('pending', ['action_slug' => 'broken']);

    app(TaskOperator::class)->cancel($broken);

    expect($broken->fresh()->status)->toBe(TaskStatus::CANCELLED);
    $message = $broken->events()->where('type', 'cancelled')->sole()->message;
    expect($message)->toContain('could not roll back')->toContain('could not tidy up');
});

class BrokenOperatorAction extends OperatorAction
{
    public static function compensate(Task $task): void
    {
        throw new RuntimeException('could not roll back');
    }

    public static function cancelled(Task $task): ?string
    {
        throw new RuntimeException('could not tidy up');
    }
}

it('does not run, queue or claim a cancelled task', function () {
    Queue::fake();
    $task = operatorTask('pending');
    app(TaskOperator::class)->cancel($task);

    expect($task->fresh()->claim())->toBeFalse();
    expect(app(TaskDispatcher::class)->queue($task->fresh()))->toBeFalse();
    Queue::assertNothingPushed();
});

it('does not count a cancelled task as one still to do', function () {
    $task = operatorTask('pending');
    app(TaskOperator::class)->cancel($task);

    expect(TaskStatus::openValues())->not->toContain('cancelled');
});

it('blocks what waited for a cancelled task, and frees it when that is retried', function () {
    Queue::fake();
    $task = operatorTask('pending');
    $dependent = operatorTask('pending');
    $dependent->dependOn($task);
    $dispatcher = app(TaskDispatcher::class);

    app(TaskOperator::class)->cancel($task);

    expect(app(TaskDependencies::class)->state($dependent, Task::GATE_START)['failed']->pluck('id')->all())->toBe([$task->id]);
    $dispatcher->promote();
    expect($dependent->fresh()->status)->toBe(TaskStatus::BLOCKED);

    app(TaskOperator::class)->retry($task->fresh());
    $dispatcher->unblock();

    expect($dependent->fresh()->status)->toBe(TaskStatus::PENDING);
});

it('deletes a task that is over, and frees what waited for it', function (string $status) {
    $task = operatorTask($status);
    $dependent = operatorTask('pending');
    $dependent->dependOn($task);

    app(TaskOperator::class)->delete($task);

    expect(Task::find($task->id))->toBeNull();
    expect(DB::table('task_dependencies')->where('task_id', $dependent->id)->count())->toBe(0);
})->with(['complete', 'failed', 'cancelled']);

it('refuses to delete a task that has not finished, and says to cancel it', function (string $status) {
    $task = operatorTask($status);

    expect(fn () => app(TaskOperator::class)->delete($task))
        ->toThrow(TaskOperationRefused::class, __('admin.tasks.not_deletable'));
    expect(Task::find($task->id))->not->toBeNull();
})->with(['pending', 'ready', 'queued', 'in_progress', 'blocked']);

it('does one thing to many tasks, skipping the ones it is not allowed for', function () {
    Queue::fake();
    $failed = operatorTask('failed');
    $running = operatorTask('in_progress', ['claimed_at' => now()]);
    $done = operatorTask('complete');

    $retry = app(TaskOperator::class)->bulk('retry', [$failed, $running, $done]);
    $cancel = app(TaskOperator::class)->bulk('cancel', [$running->fresh(), operatorTask('pending'), $done]);
    $delete = app(TaskOperator::class)->bulk('delete', [$done, $running->fresh()]);

    expect($retry['done'])->toBe(1);
    expect(array_keys($retry['refused']))->toBe([$running->id, $done->id]);
    expect($cancel['done'])->toBe(1);
    expect($cancel['refused'])->toHaveKeys([$running->id, $done->id]);
    expect($delete['done'])->toBe(1);
    expect($delete['refused'])->toHaveKey($running->id);
    expect(Task::find($done->id))->toBeNull();
});
