<?php

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Enums\TaskStatus;
use App\Exceptions\ConnectionFailedException;
use App\Exceptions\SSONotReadyException;
use App\Exceptions\Tasks\PermanentFailure;
use App\Jobs\RunAction;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Tests\Support\TestSupports;

/**
 * TaskRunner: what a TaskAction's result, or its exception, does to the task,
 * and that an action written the old way is driven exactly as it was.
 */
class RunnerNative extends NativeAction
{
    public static ?Closure $handle = null;

    public static ?Closure $poll = null;

    /** @var array<int, int> */
    public static array $compensated = [];

    public static function handle(Task $task): ActionResult
    {
        return (self::$handle ?? fn () => ActionResult::done())($task);
    }

    public static function poll(Task $task): ActionResult
    {
        return (self::$poll ?? fn () => ActionResult::done())($task);
    }

    public static function compensate(Task $task): void
    {
        self::$compensated[] = $task->id;
    }
}

class RunnerNativeRetrying extends RunnerNative
{
    public static int $max_attempts = 3;
}

class RunnerCompensateExploding extends RunnerNative
{
    public static function handle(Task $task): ActionResult
    {
        return ActionResult::fail('bad');
    }

    public static function compensate(Task $task): void
    {
        throw new RuntimeException('could not roll back');
    }
}

// Written the old way: static methods that change the task themselves, one
// taking it by reference
class RunnerLegacy extends Action
{
    public static ?Closure $run = null;

    public static function run(Task $task)
    {
        return (self::$run)($task);
    }

    public static function complete(Task &$task)
    {
        $task->error_message = 'polled';
        $task->save();
    }
}

beforeEach(function () {
    (new TestSupports)->seed();

    RunnerNative::$handle = null;
    RunnerNative::$poll = null;
    RunnerNative::$compensated = [];
    RunnerLegacy::$run = null;

    app('actions')->register('runner.native', RunnerNative::class);
    app('actions')->register('runner.retrying', RunnerNativeRetrying::class);
    app('actions')->register('runner.legacy', RunnerLegacy::class);
    app('actions')->register('runner.exploding', RunnerCompensateExploding::class);
});

function runnerTask(string $slug = 'native', array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'runner',
        'action_slug' => $slug,
        'task_group' => 5,
        'status' => 'ready',
        'description' => 'Runner test task',
    ], $attributes));
    $task->save();

    return $task;
}

function runnerRun(Task $task): void
{
    (new RunAction($task))->handle();
}

function runnerEvents(Task $task): array
{
    return $task->events()->pluck('type')->all();
}

it('completes a task whose action returns done', function () {
    RunnerNative::$handle = fn () => ActionResult::done(['answer' => 42]);
    $task = runnerTask(attributes: ['error_message' => 'old']);

    runnerRun($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::COMPLETE);
    expect($fresh->getValue('answer'))->toBe(42);
    expect($fresh->error_message)->toBe('');
    expect($fresh->claimed_at)->toBeNull();
    expect(runnerEvents($task))->toBe(['started', 'completed']);
});

it('leaves a task in progress when its action says to wait, keeping the values', function () {
    RunnerNative::$handle = fn () => ActionResult::wait(['job_id' => 'abc']);
    $task = runnerTask();

    runnerRun($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($fresh->getValue('job_id'))->toBe('abc');
    expect($fresh->attempts)->toBe(1);
    expect(runnerEvents($task))->toBe(['started']);
});

it('puts a task back to pending when its action asks to retry later', function () {
    RunnerNative::$handle = fn () => ActionResult::retryLater('not yet', 30);
    $task = runnerTask();

    runnerRun($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::PENDING);
    expect($fresh->error_message)->toBe('not yet');
    expect($fresh->error_code)->toBe('waiting');
    expect($fresh->next_attempt_at->between(now()->addSeconds(25), now()->addSeconds(35)))->toBeTrue();
    expect($fresh->claimed_at)->toBeNull();
    expect(runnerEvents($task))->toBe(['started', 'retry_scheduled']);
});

it('rolls back and fails a task whose action returns fail', function () {
    RunnerNative::$handle = fn () => ActionResult::fail('no good', 'my_code');
    $task = runnerTask();

    runnerRun($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_code)->toBe('my_code');
    expect($fresh->error_message)->toBe('no good');
    expect(RunnerNative::$compensated)->toBe([$task->id]);
    expect(runnerEvents($task))->toBe(['started', 'failed']);
});

it('runs what the action wants done once the new state is saved', function () {
    $seen = [];
    // Not an arrow function: that would capture $seen by value
    RunnerNative::$handle = function () use (&$seen) {
        return ActionResult::done()->then(function (Task $task) use (&$seen) {
            $seen[] = Task::find($task->id)->status;
        });
    };

    runnerRun(runnerTask());

    expect($seen)->toBe([TaskStatus::COMPLETE]);
});

it('waits out an exception that says something is not ready, without failing', function () {
    RunnerNative::$handle = fn () => throw new SSONotReadyException('sso is starting', 90);
    $task = runnerTask();

    runnerRun($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::PENDING);
    expect($fresh->error_code)->toBe('waiting');
    expect($fresh->error_message)->toBe('sso is starting');
    expect($fresh->next_attempt_at->between(now()->addSeconds(85), now()->addSeconds(95)))->toBeTrue();
    expect(runnerEvents($task))->toBe(['started', 'waiting']);
});

it('retries a connection failure later, waiting longer each time', function () {
    RunnerNative::$handle = fn () => throw new ConnectionFailedException('unreachable');
    $first = runnerTask();
    $third = runnerTask(attributes: ['attempts' => 2]);

    runnerRun($first);
    runnerRun($third);

    expect($first->fresh()->status)->toBe(TaskStatus::PENDING);
    expect($first->fresh()->error_code)->toBe('connection_failed');
    expect($first->fresh()->job_id)->toBe(0);
    expect($first->fresh()->next_attempt_at->between(now()->addSeconds(55), now()->addSeconds(65)))->toBeTrue();
    // The third attempt waits the third backoff step
    expect($third->fresh()->next_attempt_at->between(now()->addSeconds(295), now()->addSeconds(305)))->toBeTrue();
});

it('fails a task at once when its action throws a permanent failure', function () {
    RunnerNative::$handle = fn () => throw new PermanentFailure('can never work');
    $task = runnerTask();

    runnerRun($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_code)->toBe('permanent');
    expect(RunnerNative::$compensated)->toBe([$task->id]);
});

it('lets an error it does not know through, so the queue fails the job', function () {
    RunnerNative::$handle = fn () => throw new RuntimeException('boom');
    $task = runnerTask();

    expect(fn () => runnerRun($task))->toThrow(RuntimeException::class, 'boom');
    expect($task->fresh()->claimed_at)->toBeNull();

    // What the queue then does
    app(TaskRunner::class)->fail($task, new RuntimeException('boom'));

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_code)->toBe('throwable');
    expect($fresh->error_message)->toBe('boom');
    expect(RunnerNative::$compensated)->toBe([$task->id]);
});

it('retries an unknown error only for an action that allows more attempts', function () {
    RunnerNative::$handle = fn () => throw new RuntimeException('flaky');
    $second = runnerTask('retrying', ['attempts' => 1]);
    $last = runnerTask('retrying', ['attempts' => 2]);

    runnerRun($second);
    expect($second->fresh()->status)->toBe(TaskStatus::PENDING);
    expect($second->fresh()->error_code)->toBe('error');

    expect(fn () => runnerRun($last))->toThrow(RuntimeException::class, 'flaky');
});

it('fails the task even if rolling it back throws', function () {
    $task = runnerTask('exploding');

    runnerRun($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($task->fresh()->error_message)->toBe('bad');
});

// PHP itself rejects a handle() that returns something other than an ActionResult
it('rejects a native action that returns a legacy result', function () {
    RunnerNative::$handle = fn () => ActionResult::legacy();
    $task = runnerTask();

    expect(fn () => ActionFacade::run($task))->toThrow(LogicException::class);
});

it('polls a native action until it is done', function () {
    $task = runnerTask('native', ['status' => 'in_progress']);

    RunnerNative::$poll = fn () => ActionResult::wait();
    app(TaskRunner::class)->poll($task);
    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);

    RunnerNative::$poll = fn () => ActionResult::done();
    app(TaskRunner::class)->poll($task);
    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('keeps a task in progress, with the reason, when polling it throws', function () {
    RunnerNative::$poll = fn () => throw new ConnectionFailedException('server down');
    $task = runnerTask('native', ['status' => 'in_progress']);

    app(TaskRunner::class)->tryPoll($task);
    app(TaskRunner::class)->tryPoll($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($fresh->error_code)->toBe('connection_failed');
    expect($fresh->error_message)->toBe('server down');
    // The same problem twice is one row, not two
    expect(runnerEvents($task))->toBe(['poll_error']);
});

it('fails a task whose polling throws a permanent failure', function () {
    RunnerNative::$poll = fn () => throw new PermanentFailure('gone for good');
    $task = runnerTask('native', ['status' => 'in_progress']);

    app(TaskRunner::class)->tryPoll($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($task->fresh()->error_code)->toBe('permanent');
});

it('drives a legacy action exactly as before', function () {
    $returned = new class extends Action
    {
        public function __construct()
        {
            $this->setCustomValues(['from_run' => true]);
        }
    };
    RunnerLegacy::$run = fn () => $returned;
    $task = runnerTask('legacy');

    runnerRun($task);

    // The values it returned are kept; its status is its own business
    $fresh = $task->fresh();
    expect($fresh->getValue('from_run'))->toBeTrue();
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);

    // complete() takes the task by reference
    app(TaskRunner::class)->poll($task);
    expect($task->fresh()->error_message)->toBe('polled');
});

it('leaves a status a legacy action set itself alone', function () {
    RunnerLegacy::$run = function (Task $task) {
        $task->status = TaskStatus::FAILED;
        $task->error_message = 'I failed';
        $task->save();
    };
    $task = runnerTask('legacy');

    runnerRun($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($task->fresh()->error_message)->toBe('I failed');
});

it('has nothing to run for an action that is not registered', function () {
    $task = runnerTask('unknown');

    expect(fn () => ActionFacade::run($task))->toThrow(Exception::class, 'hasn\'t been registered');
});

it('retries a task whose action has no fresh action to give', function () {
    Illuminate\Support\Facades\Queue::fake();
    $task = runnerTask('native', ['status' => 'failed', 'error_message' => 'before']);

    ActionFacade::retry($task);

    // RunnerNative::retry() returns null: the task is retried as it is
    expect($task->fresh()->status)->toBe(TaskStatus::QUEUED);
    Illuminate\Support\Facades\Queue::assertPushed(RunAction::class, 1);
});
