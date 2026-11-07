<?php

use App\AccountTest;
use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\NativeAction;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Tests\CreateTestOrganization;
use App\Application;
use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Organization;
use App\Plan;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

/**
 * Some actions delete their task (and others) to say they have finished. What
 * was waiting for them must find out straight away, not on the next scheduled
 * pass: a chain of them used to cost a minute for every link.
 */
class WakeWaiter extends NativeAction
{
    /** @var array<int, int> */
    public static array $polled = [];

    public static function handle(Task $task): ActionResult
    {
        return ActionResult::wait();
    }

    public static function poll(Task $task): ActionResult
    {
        self::$polled[] = $task->id;

        return ActionResult::done();
    }
}

beforeEach(function () {
    (new TestSupports)->seed();
    WakeWaiter::$polled = [];
    app('actions')->register('wake.waiter', WakeWaiter::class);
    Queue::fake();
});

function wakeTask(array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::where('type', 'superaccount')->value('id'),
        'action_group' => 'wake',
        'action_slug' => 'waiter',
        'task_group' => 7,
        'status' => 'in_progress',
        'description' => 'Wake test task',
    ], $attributes));
    $task->save();

    return $task;
}

// ─── The runner ──────────────────────────────────────────────────────────────

it('finds the tasks waiting for some tasks, leaving out those tasks themselves', function () {
    $a = wakeTask();
    $b = wakeTask();
    $waiting = wakeTask();
    $waiting->dependOn([$a, $b], Task::GATE_FINISH);
    $both = wakeTask();
    $both->dependOn($a, Task::GATE_FINISH);
    $a->dependOn($b, Task::GATE_FINISH);
    wakeTask();

    $found = app(TaskRunner::class)->dependentsOf([$a, $b->id]);

    expect($found)->toEqualCanonicalizing([$waiting->id, $both->id]);
});

it('polls a task that waited for tasks that have since been deleted', function () {
    $child = wakeTask();
    $parent = wakeTask();
    $parent->dependOn($child, Task::GATE_FINISH);
    $runner = app(TaskRunner::class);

    $waiting = $runner->dependentsOf([$child]);
    Task::whereKey($child->id)->delete();
    expect(WakeWaiter::$polled)->toBe([]);

    $runner->wake($waiting);

    expect(WakeWaiter::$polled)->toBe([$parent->id]);
    expect($parent->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('leaves a task that still waits for something else waiting', function () {
    $gone = wakeTask();
    $still_running = wakeTask();
    $parent = wakeTask();
    $parent->dependOn([$gone, $still_running], Task::GATE_FINISH);
    $runner = app(TaskRunner::class);

    $waiting = $runner->dependentsOf([$gone]);
    Task::whereKey($gone->id)->delete();
    $runner->wake($waiting);

    expect(WakeWaiter::$polled)->toBe([]);
    expect($parent->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
});

it('does not poll a waiting task that is being run right now', function () {
    $child = wakeTask();
    $parent = wakeTask(['claimed_at' => now()]);
    $parent->dependOn($child, Task::GATE_FINISH);
    $runner = app(TaskRunner::class);

    $waiting = $runner->dependentsOf([$child]);
    Task::whereKey($child->id)->delete();
    $runner->wake($waiting);

    expect(WakeWaiter::$polled)->toBe([]);
});

it('starts a pending task that was only waiting for tasks that have been deleted', function () {
    $child = wakeTask();
    $pending = wakeTask(['status' => 'pending']);
    $pending->dependOn($child);
    $runner = app(TaskRunner::class);

    $waiting = $runner->dependentsOf([$child]);
    Task::whereKey($child->id)->delete();
    $runner->wake($waiting);

    Queue::assertPushed(RunAction::class, fn (RunAction $job) => $job->task->is($pending));
});

it('skips a waiting task that has been deleted too', function () {
    $child = wakeTask();
    $parent = wakeTask();
    $parent->dependOn($child, Task::GATE_FINISH);
    $runner = app(TaskRunner::class);

    $waiting = $runner->dependentsOf([$child]);
    Task::whereKey([$child->id, $parent->id])->delete();
    $runner->wake($waiting);

    expect(WakeWaiter::$polled)->toBe([]);
});

// ─── The actions that delete their tasks to say they have finished ───────────

it('tells what waits for an app delete as soon as the app is gone', function () {
    (new TestSupports)->activateDemoApp();
    $instance = Application::where('slug', 'demo_app')->first()->instances()->first();

    $delete = ActionFacade::execute(new ApplicationDelete($instance));
    $delete->status = TaskStatus::IN_PROGRESS;
    $delete->save();
    $parent = wakeTask();
    $parent->dependOn($delete, Task::GATE_FINISH);

    app(TaskRunner::class)->poll($delete);

    expect(Task::find($delete->id))->toBeNull();
    expect(WakeWaiter::$polled)->toBe([$parent->id]);
    expect($parent->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('tells what waits for an organization delete as soon as the organization is gone', function () {
    $account_test = new AccountTest;
    $account_test->organization_id = 1;
    $account_test->created_by_id = 1;
    $account_test->test_number = 1;
    $account_test->description = 'Wake test';
    $account_test->settings = ['base_plan' => null, 'apps' => []];
    $account_test->status = 'in_progress';
    $account_test->save();
    [$organization] = (new CreateTestOrganization)->create($account_test, Plan::where('is_default', 1)->firstOrFail(), CreateTestOrganization::lastNumber() + 1);

    $deletion = ActionFacade::execute(new DeleteOrganization($organization));
    $deletion->status = TaskStatus::IN_PROGRESS;
    $deletion->save();
    // Belongs to the super account, so it outlives the organization, as a test step's clean-up does
    $parent = wakeTask();
    $parent->dependOn($deletion, Task::GATE_FINISH);

    app(TaskRunner::class)->poll($deletion);

    expect(Organization::find($organization->id))->toBeNull();
    expect(WakeWaiter::$polled)->toBe([$parent->id]);
    expect($parent->fresh()->status)->toBe(TaskStatus::COMPLETE);
    expect(DB::table('task_dependencies')->count())->toBe(0);
});
