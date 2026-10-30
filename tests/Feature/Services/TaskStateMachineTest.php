<?php

use App\Actions\Action;
use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Organization;
use App\Services\Tasks\TaskDispatcher;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\TestSupports;

/**
 * The task state machine: the TaskStatus enum and its cast, the atomic
 * transition/claim a task is moved and run with, and the runners built on them.
 */
class StateMachineRecorder extends Action
{
    public static int $runs = 0;

    /** @var array<int, int> */
    public static array $completed = [];

    /** @var array<int, int> */
    public static array $reverted = [];

    public static function run(Task $task)
    {
        self::$runs++;
    }

    public static function complete(Task $task)
    {
        self::$completed[] = $task->id;
    }

    public static function revert(Task $task)
    {
        self::$reverted[] = $task->id;
    }
}

class StateMachineExploding extends Action
{
    public static function run(Task $task)
    {
        throw new RuntimeException('boom');
    }
}

beforeEach(function () {
    (new TestSupports)->seed();

    StateMachineRecorder::$runs = 0;
    StateMachineRecorder::$completed = [];
    StateMachineRecorder::$reverted = [];

    app('actions')->register('state_machine.recorder', StateMachineRecorder::class);
    app('actions')->register('state_machine.exploding', StateMachineExploding::class);
});

function stateTask(array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => Organization::find(1)->id,
        'action_group' => 'state_machine',
        'action_slug' => 'recorder',
        'task_group' => 1,
        'status' => 'pending',
        'description' => 'State machine test task',
    ], $attributes));
    $task->save();

    return $task;
}

it('keeps the enum values identical to the statuses already stored', function () {
    expect(array_map(fn (TaskStatus $status) => $status->value, TaskStatus::cases()))
        ->toBe(['pending', 'ready', 'queued', 'in_progress', 'complete', 'failed', 'blocked']);
});

it('treats every status but complete as open', function () {
    expect(TaskStatus::openValues())->toBe(['pending', 'ready', 'queued', 'in_progress', 'failed', 'blocked']);
    expect(TaskStatus::FAILED->isOpen())->toBeTrue();
    expect(TaskStatus::COMPLETE->isOpen())->toBeFalse();
});

it('knows which status changes tasks make', function () {
    expect(TaskStatus::PENDING->canTransitionTo(TaskStatus::READY))->toBeTrue();
    expect(TaskStatus::READY->canTransitionTo(TaskStatus::QUEUED))->toBeTrue();
    expect(TaskStatus::QUEUED->canTransitionTo(TaskStatus::READY))->toBeTrue();
    expect(TaskStatus::READY->canTransitionTo(TaskStatus::READY))->toBeTrue();
    expect(TaskStatus::FAILED->canTransitionTo(TaskStatus::COMPLETE))->toBeFalse();
    expect(TaskStatus::COMPLETE->canTransitionTo(TaskStatus::PENDING))->toBeFalse();
});

it('casts the stored status to the enum', function () {
    $task = stateTask(['status' => 'queued']);

    expect($task->fresh()->status)->toBe(TaskStatus::QUEUED);
    expect(DB::table('tasks')->where('id', $task->id)->value('status'))->toBe('queued');

    $task->status = TaskStatus::COMPLETE;
    $task->save();

    expect(DB::table('tasks')->where('id', $task->id)->value('status'))->toBe('complete');
});

it('logs a status change nothing is known to make, without blocking it', function () {
    Log::spy();
    $task = stateTask(['status' => 'complete']);

    $task->status = TaskStatus::PENDING;
    $task->save();

    expect($task->fresh()->status)->toBe(TaskStatus::PENDING);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'complete -> pending'));
});

it('lets only one of two racing processes make a move', function () {
    $task = stateTask(['status' => 'ready']);
    $first = Task::find($task->id);
    $second = Task::find($task->id);

    expect($first->transition(TaskStatus::QUEUED, TaskStatus::READY, ['queued_at' => now()]))->toBeTrue();
    expect($second->transition(TaskStatus::QUEUED, TaskStatus::READY, ['queued_at' => now()]))->toBeFalse();

    expect($first->status)->toBe(TaskStatus::QUEUED);
    expect($first->queued_at)->not->toBeNull();
    expect($first->isDirty())->toBeFalse();
    expect($task->fresh()->status)->toBe(TaskStatus::QUEUED);
});

it('only moves a task out of the statuses it is told to', function () {
    $task = stateTask(['status' => 'in_progress']);

    expect($task->transition(TaskStatus::READY, TaskStatus::PENDING))->toBeFalse();
    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);

    expect($task->transition(TaskStatus::FAILED, [TaskStatus::PENDING, TaskStatus::IN_PROGRESS], ['error_code' => 'test']))->toBeTrue();
    expect($task->fresh()->error_code)->toBe('test');
});

it('lets only one run claim a task', function () {
    $task = stateTask(['status' => 'ready', 'queued_at' => now()]);
    $first = Task::find($task->id);
    $second = Task::find($task->id);

    expect($first->claim())->toBeTrue();
    expect($second->claim())->toBeFalse();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($fresh->claimed_at)->not->toBeNull();
    expect($fresh->queued_at)->toBeNull();

    $first->release();
    expect($task->fresh()->claimed_at)->toBeNull();
    expect($second->claim())->toBeTrue();
});

it('claims an in_progress task but not a finished one', function () {
    expect(stateTask(['status' => 'in_progress'])->claim())->toBeTrue();
    expect(stateTask(['status' => 'pending'])->claim())->toBeTrue();
    expect(stateTask(['status' => 'complete'])->claim())->toBeFalse();
    expect(stateTask(['status' => 'failed'])->claim())->toBeFalse();
});

it('takes over a claim that has outlived the lease', function () {
    $task = stateTask(['status' => 'in_progress', 'claimed_at' => now()->subSeconds(Task::CLAIM_LEASE_SECONDS - 60)]);
    expect($task->claim())->toBeFalse();

    DB::table('tasks')->where('id', $task->id)->update(['claimed_at' => now()->subSeconds(Task::CLAIM_LEASE_SECONDS + 60)]);
    expect($task->claim())->toBeTrue();
});

it('queues each ready task once however often queueReady runs', function () {
    Queue::fake();
    $task = stateTask(['status' => 'ready', 'error_message' => 'previous attempt']);

    app(TaskDispatcher::class)->queueReady();
    app(TaskDispatcher::class)->queueReady();

    Queue::assertPushed(RunAction::class, 1);
    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::QUEUED);
    expect($fresh->queued_at)->not->toBeNull();
    expect($fresh->error_message)->toBeNull();
});

it('readies pending tasks whose prerequisites pass and leaves waiting ones', function () {
    $free = stateTask(['status' => 'pending', 'notified' => 1]);
    $blocker = stateTask(['status' => 'in_progress']);
    $waiting = stateTask(['status' => 'pending']);
    $waiting->dependOn($blocker);

    app(TaskDispatcher::class)->promote();

    expect($free->fresh()->status)->toBe(TaskStatus::READY);
    expect($free->fresh()->notified)->toBe(0);
    expect($waiting->fresh()->status)->toBe(TaskStatus::PENDING);
    expect($waiting->fresh()->error_message)->toBe(__('messages.action.waiting_for'));
});

it('runs a task once and releases its claim', function () {
    $task = stateTask(['status' => 'ready']);

    (new RunAction($task))->handle();

    $fresh = $task->fresh();
    expect(StateMachineRecorder::$runs)->toBe(1);
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($fresh->attempts)->toBe(1);
    expect($fresh->claimed_at)->toBeNull();
});

it('does not run a task another run holds, or one that is finished', function () {
    $claimed = stateTask(['status' => 'in_progress', 'claimed_at' => now()]);
    $finished = stateTask(['status' => 'complete']);

    (new RunAction($claimed))->handle();
    (new RunAction($finished))->handle();

    expect(StateMachineRecorder::$runs)->toBe(0);
    // The run that lost must not let go of the winner's claim
    expect($claimed->fresh()->claimed_at)->not->toBeNull();
    expect($claimed->fresh()->attempts)->toBe(0);
});

it('releases the claim when the action throws', function () {
    $task = stateTask(['status' => 'ready', 'action_slug' => 'exploding']);

    expect(fn () => (new RunAction($task))->handle())->toThrow(RuntimeException::class, 'boom');

    expect($task->fresh()->claimed_at)->toBeNull();
});

it('does not poll a task while it is still being run', function () {
    $running = stateTask(['status' => 'in_progress', 'claimed_at' => now()]);
    $idle = stateTask(['status' => 'in_progress']);
    $stale = stateTask(['status' => 'in_progress', 'claimed_at' => now()->subSeconds(Task::CLAIM_LEASE_SECONDS + 60)]);

    app(TaskDispatcher::class)->poll();

    expect(StateMachineRecorder::$completed)->toEqualCanonicalizing([$idle->id, $stale->id]);
    expect(StateMachineRecorder::$completed)->not->toContain($running->id);
});

it('queues a task again when its job was lost', function () {
    $lost = stateTask(['status' => 'queued', 'queued_at' => now()->subMinutes(Task::QUEUED_TIMEOUT_MINUTES + 1)]);
    $slow = stateTask(['status' => 'queued', 'queued_at' => now()->subMinute()]);
    // Queued before queued_at existed: judged by its last update instead
    $legacy = stateTask(['status' => 'queued']);
    DB::table('tasks')->where('id', $legacy->id)->update(['updated_at' => now()->subMinutes(Task::QUEUED_TIMEOUT_MINUTES + 5)]);

    app(TaskDispatcher::class)->reap();

    expect($lost->fresh()->status)->toBe(TaskStatus::READY);
    expect($lost->fresh()->queued_at)->toBeNull();
    expect($legacy->fresh()->status)->toBe(TaskStatus::READY);
    expect($slow->fresh()->status)->toBe(TaskStatus::QUEUED);
});

it('rolls back and fails a task that missed its deadline', function () {
    $late = stateTask(['status' => 'in_progress', 'deadline_at' => now()->subMinute()]);
    $on_time = stateTask(['status' => 'in_progress', 'deadline_at' => now()->addHour()]);
    $no_deadline = stateTask(['status' => 'in_progress']);
    $running = stateTask(['status' => 'in_progress', 'deadline_at' => now()->subMinute(), 'claimed_at' => now()]);
    $finished = stateTask(['status' => 'complete', 'deadline_at' => now()->subMinute()]);

    app(TaskDispatcher::class)->reap();

    $failed = $late->fresh();
    expect($failed->status)->toBe(TaskStatus::FAILED);
    expect($failed->error_code)->toBe('deadline_exceeded');
    expect($failed->error_message)->toBe(__('messages.action.deadline_exceeded'));
    expect(StateMachineRecorder::$reverted)->toBe([$late->id]);

    expect($on_time->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($no_deadline->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($running->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($finished->fresh()->status)->toBe(TaskStatus::COMPLETE);
});

it('sets a deadline on a task only when its action asks for one', function () {
    Queue::fake();
    $organization = Organization::find(1);

    $with_deadline = new class($organization) extends Action
    {
        public $slug = 'state_machine_deadline';

        public ?int $deadline_minutes = 30;

        public function __construct(Organization $organization)
        {
            $this->organization = $organization;
            $this->description = 'With a deadline';
        }
    };
    $without_deadline = new class($organization) extends Action
    {
        public $slug = 'state_machine_no_deadline';

        public function __construct(Organization $organization)
        {
            $this->organization = $organization;
            $this->description = 'Without a deadline';
        }
    };

    $timed = ActionFacade::execute($with_deadline);
    $untimed = ActionFacade::execute($without_deadline);

    expect($timed->deadline_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();
    expect($untimed->deadline_at)->toBeNull();
});

it('resets a retried task and queues it to be claimed again', function () {
    Queue::fake();
    $task = stateTask(['status' => 'failed', 'claimed_at' => now(), 'queued_at' => now()->subHour()]);

    ActionFacade::retry($task);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::QUEUED);
    expect($fresh->claimed_at)->toBeNull();
    expect($fresh->queued_at->isAfter(now()->subMinute()))->toBeTrue();
    Queue::assertPushed(RunAction::class, 1);
});

it('renders the admin dashboard with the task counts', function () {
    stateTask(['status' => 'failed']);
    stateTask(['status' => 'pending']);

    $this->actingAs(User::find(1))
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('task_statuses.failed', 1)
            ->where('task_statuses.pending', 1));
});

it('filters the admin task list by status and ignores one it does not know', function () {
    stateTask(['status' => 'failed']);
    stateTask(['status' => 'pending']);
    $this->actingAs(User::find(1));

    $this->postJson('/admin/server/tasks/api', ['status' => 'failed'])
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('tasks.0.status', 'failed');

    $this->postJson('/admin/server/tasks/api', ['status' => 'nonsense'])
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});
