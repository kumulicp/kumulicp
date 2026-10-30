<?php

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Organization;
use App\Services\Tasks\TaskDispatcher;
use App\Services\Tasks\TaskRunner;
use App\Support\ConvertLegacyDependencies;
use App\Support\Facades\Action as ActionFacade;
use App\Support\TaskHelpers;
use App\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * Dependencies between tasks: what a task waits for before it runs (start) and
 * before it completes (finish), the Blocked status a failed dependency causes,
 * and how in-flight tasks in the old formats are converted.
 */
class DepsNative extends NativeAction
{
    /** @var array<int, int> */
    public static array $polled = [];

    public static ?Closure $handle = null;

    public static function handle(Task $task): ActionResult
    {
        return (self::$handle ?? fn () => ActionResult::done())($task);
    }

    public static function poll(Task $task): ActionResult
    {
        self::$polled[] = $task->id;

        return ActionResult::done();
    }
}

class DepsLegacy extends Action
{
    public static ?Closure $run = null;

    public static function run(Task $task)
    {
        return (self::$run)($task);
    }
}

beforeEach(function () {
    (new TestSupports)->seed();

    DepsNative::$polled = [];
    DepsNative::$handle = null;
    DepsLegacy::$run = null;

    app('actions')->register('deps.native', DepsNative::class);
    app('actions')->register('deps.legacy', DepsLegacy::class);
});

function depTask(array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'deps',
        'action_slug' => 'native',
        'task_group' => 3,
        'status' => 'pending',
        'description' => 'Dependency test task',
    ], $attributes));
    $task->save();

    return $task;
}

function depIds(Task $task, string $gate): array
{
    return DB::table('task_dependencies')->where('task_id', $task->id)->where('gate', $gate)->pluck('depends_on_task_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

function depAction(Organization $organization, string $slug = 'deps_anonymous'): Action
{
    return new class($organization, $slug) extends Action
    {
        public function __construct(Organization $organization, string $slug)
        {
            $this->organization = $organization;
            $this->slug = $slug;
            $this->description = 'Anonymous action';
        }
    };
}

it('records what a task waits for, once, from tasks or ids', function () {
    $task = depTask();
    $first = depTask();
    $second = depTask();

    $task->dependOn($first);
    $task->dependOn([$first, $second->id]);
    $task->dependOn($second, Task::GATE_FINISH);

    expect(depIds($task, 'start'))->toBe([$first->id, $second->id]);
    expect(depIds($task, 'finish'))->toBe([$second->id]);
    expect($task->dependencies()->count())->toBe(3);
    expect($first->dependents()->pluck('tasks.id')->all())->toBe([$task->id]);
});

it('refuses a task that would end up waiting for itself', function () {
    $a = depTask();
    $b = depTask();
    $c = depTask();
    $b->dependOn($a);
    $c->dependOn($b);

    expect(fn () => $a->dependOn($a))->toThrow(LogicException::class);
    expect(fn () => $a->dependOn($c))->toThrow(LogicException::class);
    expect(depIds($a, 'start'))->toBe([]);
});

it('stops waiting for a task that is deleted', function () {
    $dependency = depTask(['status' => 'in_progress']);
    $task = depTask();
    $task->dependOn($dependency);

    expect(ActionFacade::checkPrerequisites($task)->passed)->toBeFalse();

    // ApplicationDelete deletes its own task to say it finished
    $dependency->delete();

    expect(DB::table('task_dependencies')->count())->toBe(0);
    expect(ActionFacade::checkPrerequisites($task)->passed)->toBeTrue();
});

it('holds a pending task until the task it waits for is complete', function () {
    $dependency = depTask(['status' => 'in_progress']);
    $task = depTask();
    $task->dependOn($dependency);

    app(TaskDispatcher::class)->promote();
    expect($task->fresh()->status)->toBe(TaskStatus::PENDING);
    expect($task->fresh()->error_message)->toBe(__('messages.action.waiting_for'));

    $dependency->complete();
    app(TaskDispatcher::class)->promote();
    expect($task->fresh()->status)->toBe(TaskStatus::READY);
});

it('blocks a pending task whose dependency failed, and releases it once that is retried', function () {
    $dependency = depTask(['status' => 'failed']);
    $task = depTask();
    $task->dependOn($dependency);
    $dispatcher = app(TaskDispatcher::class);

    $dispatcher->promote();

    $blocked = $task->fresh();
    expect($blocked->status)->toBe(TaskStatus::BLOCKED);
    expect($blocked->blocked_from)->toBe('pending');
    expect($blocked->error_code)->toBe('dependency_failed');
    expect($blocked->error_message)->toBe(__('messages.action.dependency_failed'));
    expect($task->events()->pluck('type')->all())->toBe(['blocked']);

    // Still failed: stays blocked
    $dispatcher->unblock();
    expect($task->fresh()->status)->toBe(TaskStatus::BLOCKED);

    // Retried: no longer failed, so the task waits again like any other
    $dependency->transition(TaskStatus::PENDING, TaskStatus::FAILED);
    $dispatcher->unblock();
    $released = $task->fresh();
    expect($released->status)->toBe(TaskStatus::PENDING);
    expect($released->blocked_from)->toBeNull();
    expect($released->error_message)->toBeNull();
    expect($task->events()->pluck('type')->all())->toBe(['blocked', 'unblocked']);

    $dispatcher->promote();
    expect($task->fresh()->status)->toBe(TaskStatus::PENDING);

    $dependency->complete();
    $dispatcher->promote();
    expect($task->fresh()->status)->toBe(TaskStatus::READY);
});

it('releases a blocked task when the task that failed is deleted', function () {
    $dependency = depTask(['status' => 'failed']);
    $task = depTask();
    $task->dependOn($dependency);
    app(TaskDispatcher::class)->promote();
    expect($task->fresh()->status)->toBe(TaskStatus::BLOCKED);

    $dependency->delete();
    app(TaskDispatcher::class)->unblock();

    expect($task->fresh()->status)->toBe(TaskStatus::PENDING);
});

it('blocks the tasks further down a chain too, and releases them from the front', function () {
    $failed = depTask(['status' => 'failed']);
    $middle = depTask();
    $last = depTask();
    $middle->dependOn($failed);
    $last->dependOn($middle);
    $dispatcher = app(TaskDispatcher::class);

    // A pass per link: each looks at what the one before it was a moment ago
    $dispatcher->promote();
    $dispatcher->promote();
    expect($middle->fresh()->status)->toBe(TaskStatus::BLOCKED);
    expect($last->fresh()->status)->toBe(TaskStatus::BLOCKED);

    $failed->transition(TaskStatus::PENDING, TaskStatus::FAILED);
    $dispatcher->unblock();
    $dispatcher->unblock();
    expect($middle->fresh()->status)->toBe(TaskStatus::PENDING);
    expect($last->fresh()->status)->toBe(TaskStatus::PENDING);
});

it('does not poll a running task until the tasks it waits to finish are complete', function () {
    // An action nothing can poll, so the child stays unfinished
    $child = depTask(['status' => 'in_progress', 'action_slug' => 'missing']);
    $parent = depTask(['status' => 'in_progress']);
    $parent->dependOn($child, Task::GATE_FINISH);

    app(TaskDispatcher::class)->poll();
    expect(DepsNative::$polled)->toBe([]);
    expect($parent->fresh()->error_message)->toBe(__('messages.action.waiting_for'));
    expect($parent->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
});

it('blocks a running task whose child failed, and carries on when the child is retried', function () {
    $child = depTask(['status' => 'failed']);
    $parent = depTask(['status' => 'in_progress']);
    $parent->dependOn($child, Task::GATE_FINISH);
    $dispatcher = app(TaskDispatcher::class);

    $dispatcher->poll();
    expect($parent->fresh()->status)->toBe(TaskStatus::BLOCKED);
    expect($parent->fresh()->blocked_from)->toBe('in_progress');

    $child->transition(TaskStatus::PENDING, TaskStatus::FAILED);
    $dispatcher->unblock();
    expect($parent->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);

    $child->complete();
    $dispatcher->poll();
    expect(DepsNative::$polled)->toContain($parent->id);
    expect($parent->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('does not run or poll a blocked task', function () {
    $task = depTask(['status' => 'blocked', 'blocked_from' => 'pending']);

    expect($task->claim())->toBeFalse();
    expect(app(TaskDispatcher::class)->queue($task))->toBeFalse();
});

it('lets the tasks waiting for a task carry on the moment it completes', function () {
    Queue::fake();
    $child = depTask(['status' => 'ready']);
    $parent = depTask(['status' => 'in_progress']);
    $parent->dependOn($child, Task::GATE_FINISH);
    $next = depTask();
    $next->dependOn($child);

    (new RunAction($child))->handle();

    expect($child->fresh()->status)->toBe(TaskStatus::COMPLETE);
    // The parent was polled and finished, and the next task queued, without waiting for a tick
    expect(DepsNative::$polled)->toBe([$parent->id]);
    expect($parent->fresh()->status)->toBe(TaskStatus::COMPLETE);
    Queue::assertPushed(RunAction::class, fn (RunAction $job) => $job->task->is($next));
});

it('does not poll a waiting task that is still being run when its child completes', function () {
    $child = depTask(['status' => 'ready']);
    $parent = depTask(['status' => 'in_progress', 'claimed_at' => now()]);
    $parent->dependOn($child, Task::GATE_FINISH);

    (new RunAction($child))->handle();

    expect(DepsNative::$polled)->toBe([]);
});

it('saves what an action depends on when its task is created', function () {
    Queue::fake();
    $organization = Organization::find(1);
    $before = depTask(['status' => 'in_progress']);
    $child = depTask(['status' => 'in_progress']);

    $task = ActionFacade::execute(depAction($organization)->dependsOn($before)->waitFor($child));

    expect(depIds($task, 'start'))->toBe([$before->id]);
    expect(depIds($task, 'finish'))->toBe([$child->id]);
    // It has to wait for the task before it
    Queue::assertNothingPushed();
});

it('makes a delayed task wait for its parent', function () {
    Queue::fake();
    $organization = Organization::find(1);
    $parent = depTask(['status' => 'in_progress']);

    $delayed = ActionFacade::execute(depAction($organization, 'delayed'), $parent, delay: true);
    $not_delayed = ActionFacade::execute(depAction($organization, 'not_delayed'), $parent);

    expect(depIds($delayed, 'start'))->toBe([$parent->id]);
    expect(depIds($not_delayed, 'start'))->toBe([]);
});

it('replaces what a legacy action waits on only when it says what that is', function () {
    $organization = Organization::find(1);
    $old = depTask(['status' => 'in_progress']);
    $new = depTask(['status' => 'in_progress']);
    $task = depTask(['status' => 'ready', 'action_slug' => 'legacy']);
    $task->dependOn($old, Task::GATE_FINISH);

    // Rebuilds itself without saying: keeps what it had
    DepsLegacy::$run = fn () => depAction($organization);
    app(TaskRunner::class)->run($task);
    expect(depIds($task, 'finish'))->toBe([$old->id]);

    DepsLegacy::$run = fn () => depAction($organization)->waitFor($new);
    app(TaskRunner::class)->run($task);
    expect(depIds($task, 'finish'))->toBe([$new->id]);

    DepsLegacy::$run = fn () => depAction($organization)->waitFor();
    app(TaskRunner::class)->run($task);
    expect(depIds($task, 'finish'))->toBe([]);
});

it('lets a native action say what it waits for', function () {
    $child = depTask(['status' => 'in_progress']);
    $task = depTask(['status' => 'ready']);
    DepsNative::$handle = fn () => ActionResult::wait()->waitingFor($child);

    (new RunAction($task))->handle();

    expect(depIds($task, 'finish'))->toBe([$child->id]);
    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
});

it('forgets what a task was waiting to finish when it is retried, but not what it waits to start', function () {
    Queue::fake();
    $before = depTask(['status' => 'complete']);
    $child = depTask(['status' => 'failed']);
    $task = depTask(['status' => 'failed']);
    $task->dependOn($before);
    $task->dependOn($child, Task::GATE_FINISH);

    ActionFacade::retry($task);

    expect(depIds($task, 'start'))->toBe([$before->id]);
    expect(depIds($task, 'finish'))->toBe([]);
});

it('knows which status changes blocking makes', function () {
    expect(TaskStatus::PENDING->canTransitionTo(TaskStatus::BLOCKED))->toBeTrue();
    expect(TaskStatus::IN_PROGRESS->canTransitionTo(TaskStatus::BLOCKED))->toBeTrue();
    expect(TaskStatus::BLOCKED->canTransitionTo(TaskStatus::PENDING))->toBeTrue();
    expect(TaskStatus::BLOCKED->canTransitionTo(TaskStatus::IN_PROGRESS))->toBeTrue();
    expect(TaskStatus::BLOCKED->canTransitionTo(TaskStatus::COMPLETE))->toBeFalse();
    expect(TaskStatus::BLOCKED->isOpen())->toBeTrue();
    expect(TaskStatus::openValues())->toContain('blocked');
});

it('fails a blocked task that passes its deadline', function () {
    $task = depTask(['status' => 'blocked', 'blocked_from' => 'pending', 'deadline_at' => now()->subMinute()]);

    app(TaskDispatcher::class)->reap();

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
});

it('tells customers a blocked task is having trouble', function () {
    depTask(['status' => 'blocked', 'blocked_from' => 'pending', 'background' => 0]);

    $response = (new TaskHelpers)->getGroupStatus(['organization_id' => Organization::find(1)->id, 'background' => 0]);

    expect($response[0]['status'])->toBe('Failed');
});

it('converts the dependencies open tasks recorded the old way', function () {
    $gone = 99999;
    $child_a = depTask(['status' => 'in_progress']);
    $child_b = depTask(['status' => 'pending']);
    $before = depTask(['status' => 'in_progress']);

    $parent = depTask([
        'status' => 'in_progress',
        'custom_values' => ['waiting_for' => [$child_a->id, $gone], 'app_tasks' => [$child_b->id], 'parent_task_id' => $before->id, 'kept' => 'yes'],
    ]);
    $waiting = depTask([
        'status' => 'pending',
        'prerequisites' => json_encode(['prereqs' => [
            ['type' => 'waiting_for', 'task_id' => $before->id],
            ['type' => 'subscription_active'],
        ]]),
    ]);
    $finished = depTask([
        'status' => 'complete',
        'custom_values' => ['waiting_for' => [$child_a->id]],
    ]);

    $converter = new ConvertLegacyDependencies;

    expect($converter->convert())->toBe(3);

    expect(depIds($parent, 'finish'))->toBe([$child_a->id, $child_b->id]);
    expect(depIds($waiting, 'start'))->toBe([$before->id]);
    expect(depIds($finished, 'finish'))->toBe([]);

    expect($parent->fresh()->custom_values)->toBe(['kept' => 'yes']);
    expect(json_decode($waiting->fresh()->prerequisites, true))->toBe(['prereqs' => [['type' => 'subscription_active']]]);
    expect($finished->fresh()->custom_values)->toBe(['waiting_for' => [$child_a->id]]);

    // Running it again changes nothing
    expect($converter->convert())->toBe(0);
});
