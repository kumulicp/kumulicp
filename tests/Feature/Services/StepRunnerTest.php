<?php

use App\Actions\ActionResult;
use App\Actions\Steps\Step;
use App\Actions\Steps\StepAction;
use App\Enums\TaskStatus;
use App\Exceptions\ConnectionFailedException;
use App\Jobs\RunAction;
use App\Organization;
use App\Services\Tasks\StepRunner;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use App\TaskStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * StepRunner: a StepAction's task moves through its steps in order, waits on a
 * step that is still going, and is rolled back, last step first, if one fails.
 */
class StepsFixture
{
    /** @var array<int, string> */
    public static array $log = [];

    /** @var array<string, array<string, mixed>> step name => method => closure (or timeout minutes) */
    public static array $script = [];

    public static function call(string $name, string $method, Task $task, mixed $default): mixed
    {
        self::$log[] = "{$name}:{$method}";

        $script = self::$script[$name][$method] ?? null;

        return $script ? $script($task) : $default;
    }

    public static function count(string $entry): int
    {
        return count(array_filter(self::$log, fn ($logged) => $logged === $entry));
    }
}

abstract class ScriptedStep extends Step
{
    public function run(Task $task): ActionResult
    {
        return StepsFixture::call(static::name(), 'run', $task, ActionResult::done());
    }

    public function check(Task $task): ActionResult
    {
        return StepsFixture::call(static::name(), 'check', $task, ActionResult::done());
    }

    public function rollback(Task $task): ?string
    {
        return StepsFixture::call(static::name(), 'rollback', $task, null);
    }

    public function timeoutMinutes(): ?int
    {
        return StepsFixture::$script[static::name()]['timeout'] ?? null;
    }
}

class StepsAlpha extends ScriptedStep {}

class StepsBeta extends ScriptedStep {}

class StepsGamma extends ScriptedStep {}

class StepsFixtureAction extends StepAction
{
    public static function steps(): array
    {
        return [StepsAlpha::class, StepsBeta::class, StepsGamma::class];
    }
}

class StepsCustomMessageAction extends StepsFixtureAction
{
    public static function failureMessage(Task $task, string $reason, array $notes, array $errors): string
    {
        return "custom: {$reason}";
    }
}

beforeEach(function () {
    (new TestSupports)->seed();

    StepsFixture::$log = [];
    StepsFixture::$script = [];

    app('actions')->register('steps.fixture', StepsFixtureAction::class);
    app('actions')->register('steps.custom', StepsCustomMessageAction::class);
});

function stepsTask(string $slug = 'fixture', array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'steps',
        'action_slug' => $slug,
        'task_group' => 8,
        'status' => 'ready',
        'description' => 'Steps test task',
    ], $attributes));
    $task->save();

    return $task;
}

function stepStatuses(Task $task): array
{
    return TaskStep::where('task_id', $task->id)->orderBy('position')->pluck('status', 'name')->all();
}

function runSteps(Task $task): void
{
    (new RunAction($task))->handle();
}

it('runs steps that finish at once all in one pass', function () {
    StepsFixture::$script['steps_alpha']['run'] = fn () => ActionResult::done(['from_alpha' => 1]);
    $task = stepsTask();

    runSteps($task);

    expect(StepsFixture::$log)->toBe(['steps_alpha:run', 'steps_beta:run', 'steps_gamma:run']);
    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::COMPLETE);
    expect($fresh->getValue('from_alpha'))->toBe(1);
    expect(stepStatuses($task))->toBe(['steps_alpha' => 'done', 'steps_beta' => 'done', 'steps_gamma' => 'done']);
    expect($task->events()->pluck('type')->all())->toBe([
        'started', 'step_started', 'step_done', 'step_started', 'step_done', 'step_started', 'step_done', 'completed',
    ]);
});

it('waits on a step that is still going, then carries on from there', function () {
    StepsFixture::$script['steps_beta']['run'] = fn () => ActionResult::wait(['job' => 'abc']);
    StepsFixture::$script['steps_beta']['check'] = fn () => ActionResult::wait();
    $task = stepsTask();

    runSteps($task);

    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($task->fresh()->getValue('job'))->toBe('abc');
    expect(stepStatuses($task))->toBe(['steps_alpha' => 'done', 'steps_beta' => 'running', 'steps_gamma' => 'pending']);

    // Still going: checked, not started again
    app(TaskRunner::class)->poll($task);
    expect(StepsFixture::$log)->toBe(['steps_alpha:run', 'steps_beta:run', 'steps_beta:check']);

    StepsFixture::$script['steps_beta']['check'] = fn () => ActionResult::done();
    app(TaskRunner::class)->poll($task);

    expect(StepsFixture::$log)->toBe(['steps_alpha:run', 'steps_beta:run', 'steps_beta:check', 'steps_beta:check', 'steps_gamma:run']);
    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('does nothing while another pass has the task', function () {
    $task = stepsTask();
    $lock = Cache::lock("task-steps:{$task->id}", 60);
    expect($lock->get())->toBeTrue();

    runSteps($task);

    $lock->release();
    expect(StepsFixture::$log)->toBe([]);
    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect(TaskStep::where('task_id', $task->id)->count())->toBe(0);
});

it('does not move a task that is not in progress', function () {
    $task = stepsTask(attributes: ['status' => 'failed']);

    $result = app(StepRunner::class)->advance($task, StepsFixtureAction::class);

    expect($result->outcome)->toBe(ActionResult::WAIT);
    expect(StepsFixture::$log)->toBe([]);
});

it('rolls back the steps that were started, last first, when one fails', function () {
    StepsFixture::$script['steps_gamma']['run'] = fn () => ActionResult::fail('gamma broke');
    StepsFixture::$script['steps_beta']['rollback'] = fn () => 'beta undone';
    $task = stepsTask();

    runSteps($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_code)->toBe('step_failed');
    expect($fresh->error_message)->toBe('gamma broke beta undone');
    expect(array_values(array_filter(StepsFixture::$log, fn ($entry) => str_ends_with($entry, ':rollback'))))
        ->toBe(['steps_gamma:rollback', 'steps_beta:rollback', 'steps_alpha:rollback']);
    expect(stepStatuses($task))->toBe(['steps_alpha' => 'rolled_back', 'steps_beta' => 'rolled_back', 'steps_gamma' => 'rolled_back']);
    expect($task->events()->pluck('type')->all())->toContain('step_failed', 'step_rolled_back', 'failed');
});

it('leaves the steps that never started alone when rolling back', function () {
    StepsFixture::$script['steps_beta']['run'] = fn () => ActionResult::fail('beta broke');
    $task = stepsTask();

    runSteps($task);

    expect(StepsFixture::$log)->not->toContain('steps_gamma:run')->not->toContain('steps_gamma:rollback');
    expect(stepStatuses($task)['steps_gamma'])->toBe('pending');
});

it('only rolls a step back once, even when the task is then compensated again', function () {
    StepsFixture::$script['steps_gamma']['run'] = fn () => ActionResult::fail('gamma broke');
    $task = stepsTask();
    runSteps($task);
    $before = StepsFixture::$log;

    ActionFacade::revert($task->fresh());
    app(StepRunner::class)->rollback($task->fresh(), StepsFixtureAction::class);

    expect(StepsFixture::$log)->toBe($before);
});

it('keeps rolling back when one rollback throws, and says so', function () {
    StepsFixture::$script['steps_gamma']['run'] = fn () => ActionResult::fail('gamma broke');
    StepsFixture::$script['steps_beta']['rollback'] = function () {
        throw new RuntimeException('beta rollback broke');
    };
    $task = stepsTask();

    runSteps($task);

    expect(StepsFixture::$log)->toContain('steps_alpha:rollback');
    $message = $task->fresh()->error_message;
    expect($message)->toContain('gamma broke')->toContain('beta rollback broke');
    expect(stepStatuses($task)['steps_beta'])->toBe('rolled_back');
});

it('rolls back and fails the task, without throwing, when a step throws something unexpected', function () {
    StepsFixture::$script['steps_gamma']['run'] = function () {
        throw new RuntimeException('kaboom');
    };
    $task = stepsTask();

    runSteps($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_message)->toContain('kaboom');
    expect(StepsFixture::count('steps_alpha:rollback'))->toBe(1);
});

it('gives up on a step that is still going after its timeout, and rolls back', function () {
    StepsFixture::$script['steps_beta']['run'] = fn () => ActionResult::wait();
    StepsFixture::$script['steps_beta']['check'] = fn () => ActionResult::wait();
    StepsFixture::$script['steps_beta']['timeout'] = 10;
    $task = stepsTask();
    runSteps($task);

    // Within the timeout: still waiting
    app(TaskRunner::class)->poll($task);
    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);

    TaskStep::where('task_id', $task->id)->where('name', 'steps_beta')->update(['started_at' => now()->subMinutes(11)]);
    app(TaskRunner::class)->poll($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_message)->toContain(__('messages.action.step_timed_out', ['step' => 'steps_beta']));
    expect(StepsFixture::count('steps_beta:rollback'))->toBe(1);
    expect(StepsFixture::count('steps_alpha:rollback'))->toBe(1);
});

it('lets a step that finishes late finish', function () {
    StepsFixture::$script['steps_beta']['run'] = fn () => ActionResult::wait();
    StepsFixture::$script['steps_beta']['timeout'] = 10;
    $task = stepsTask();
    runSteps($task);

    TaskStep::where('task_id', $task->id)->where('name', 'steps_beta')->update(['started_at' => now()->subMinutes(11)]);
    // check() says done, though the deadline has passed
    app(TaskRunner::class)->poll($task);

    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('starts a step again later when it throws something that means try again later', function () {
    StepsFixture::$script['steps_beta']['run'] = function () {
        throw new ConnectionFailedException('server unreachable');
    };
    $task = stepsTask();

    runSteps($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::PENDING);
    expect($fresh->next_attempt_at)->not->toBeNull();
    expect(stepStatuses($task))->toBe(['steps_alpha' => 'done', 'steps_beta' => 'pending', 'steps_gamma' => 'pending']);

    // Retried: carries on at the step it was at
    StepsFixture::$script = [];
    $task->transition(TaskStatus::READY, TaskStatus::PENDING, ['next_attempt_at' => null]);
    runSteps($task->fresh());

    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
    expect(StepsFixture::count('steps_alpha:run'))->toBe(1);
    expect(StepsFixture::count('steps_beta:run'))->toBe(2);
});

it('lets an action say what a failure reads like', function () {
    StepsFixture::$script['steps_gamma']['run'] = fn () => ActionResult::fail('gamma broke');
    $task = stepsTask('custom');

    runSteps($task);

    expect($task->fresh()->error_message)->toBe('custom: gamma broke');
});

it('starts again from the first step when the task is retried', function () {
    Queue::fake();
    StepsFixture::$script['steps_gamma']['run'] = fn () => ActionResult::fail('gamma broke');
    $task = stepsTask();
    runSteps($task);
    expect(TaskStep::where('task_id', $task->id)->count())->toBe(3);

    ActionFacade::retry($task->fresh());

    expect(TaskStep::where('task_id', $task->id)->count())->toBe(0);
});

it('removes a task\'s steps with the task', function () {
    $task = stepsTask();
    runSteps($task);
    expect(TaskStep::where('task_id', $task->id)->count())->toBe(3);

    DB::table('tasks')->where('id', $task->id)->delete();

    expect(TaskStep::where('task_id', $task->id)->count())->toBe(0);
});

it('rejects a step that returns something a step can not', function () {
    StepsFixture::$script['steps_alpha']['run'] = fn () => ActionResult::retryLater('later');
    $task = stepsTask(attributes: ['status' => 'in_progress']);

    expect(fn () => app(StepRunner::class)->advance($task, StepsFixtureAction::class))
        ->toThrow(LogicException::class);
});
