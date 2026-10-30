<?php

use App\Actions\Action;
use App\Actions\DummyAction;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Organization;
use App\Services\ActionService;
use App\Services\SubscriptionService;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestSupports;

/**
 * Task runner safety: failed tasks stay failed, dependencies on failed tasks
 * say so, the runners are single-flight, and the framework no longer
 * unserializes database contents.
 */
function hardeningOrganization(): Organization
{
    (new TestSupports)->seed();

    return Organization::find(1);
}

function hardeningTask(Organization $organization, array $attributes = []): Task
{
    $task = new Task;
    $task->forceFill(array_merge([
        'organization_id' => $organization->id,
        'action_group' => 'system',
        'action_slug' => 'dummy_action',
        'task_group' => 1,
        'status' => 'pending',
        'description' => 'Hardening test task',
    ], $attributes));
    $task->save();

    return $task;
}

it('does not run a task whose postGenerate failed', function () {
    Queue::fake();
    $organization = hardeningOrganization();

    $failing = new class($organization) extends Action
    {
        public $slug = 'hardening_failing';

        public function __construct(Organization $organization)
        {
            $this->organization = $organization;
            $this->description = 'Failing on create';
        }

        public function postGenerate(Task $task)
        {
            throw new RuntimeException('could not prepare');
        }
    };

    $task = ActionFacade::execute($failing);

    expect($task->status)->toBe(TaskStatus::FAILED);
    // checkPrerequisites() used to blank this out on the way to dispatching
    expect($task->fresh()->error_message)->toBe('could not prepare');
    Queue::assertNotPushed(RunAction::class);
});

it('runs a task that was created successfully', function () {
    Queue::fake();
    $organization = hardeningOrganization();

    $working = new class($organization) extends Action
    {
        public $slug = 'hardening_working';

        public function __construct(Organization $organization)
        {
            $this->organization = $organization;
            $this->description = 'Working';
        }
    };

    ActionFacade::execute($working);

    Queue::assertPushed(RunAction::class, 1);
});

it('starts the tasks waiting for a task when it completes, once their prerequisites are met', function () {
    Queue::fake();
    $organization = hardeningOrganization();

    $completed = hardeningTask($organization, ['status' => 'complete']);
    $other_work = hardeningTask($organization, ['status' => 'in_progress']);
    $waiting = hardeningTask($organization);
    $waiting->dependOn($completed);
    $still_blocked = hardeningTask($organization);
    $still_blocked->dependOn([$completed, $other_work]);
    // Same group, but nothing to do with it
    $unrelated = hardeningTask($organization, ['task_group' => $completed->task_group]);

    app(TaskRunner::class)->wakeDependents($completed);

    Queue::assertPushed(RunAction::class, 1);
    Queue::assertPushed(RunAction::class, fn (RunAction $job) => $job->task->is($waiting));
    Queue::assertNotPushed(RunAction::class, fn (RunAction $job) => $job->task->is($still_blocked) || $job->task->is($unrelated));
});

it('says so when a task it waits for has failed instead of waiting on it', function () {
    $organization = hardeningOrganization();

    $failed = hardeningTask($organization, ['status' => 'failed']);
    $running = hardeningTask($organization, ['status' => 'in_progress']);

    $after_failed = hardeningTask($organization);
    $after_failed->dependOn($failed);
    $after_running = hardeningTask($organization);
    $after_running->dependOn($running);

    $failed_check = ActionFacade::checkPrerequisites($after_failed);
    $running_check = ActionFacade::checkPrerequisites($after_running);

    expect($failed_check->passed)->toBeFalse();
    expect($failed_check->blocked)->toBeTrue();
    expect($failed_check->message)->toBe(__('messages.action.dependency_failed'));
    expect($running_check->passed)->toBeFalse();
    expect($running_check->blocked)->toBeFalse();
    expect($running_check->message)->toBe(__('messages.action.waiting_for'));
});

it('blocks a running task whose child failed rather than waiting for it forever', function () {
    $organization = hardeningOrganization();

    $failed = hardeningTask($organization, ['status' => 'failed']);
    $waiting = hardeningTask($organization, ['status' => 'in_progress']);
    $waiting->dependOn($failed, Task::GATE_FINISH);

    app(TaskRunner::class)->poll($waiting);

    $fresh = $waiting->fresh();
    expect($fresh->error_message)->toBe(__('messages.action.dependency_failed'));
    expect($fresh->status)->toBe(TaskStatus::BLOCKED);
    expect($fresh->blocked_from)->toBe('in_progress');
});

it('registers an action under group.slug and rejects bad registrations', function () {
    $service = new ActionService;

    $service->register('hardening.dummy', DummyAction::class);

    $task = new Task;
    $task->forceFill(['action_group' => 'hardening', 'action_slug' => 'dummy']);
    expect($service->classFor($task))->toBe(DummyAction::class);

    // Existing registrations are untouched
    $existing = new Task;
    $existing->forceFill(['action_group' => 'system', 'action_slug' => 'dummy_action']);
    expect($service->classFor($existing))->toBe(DummyAction::class);

    expect(fn () => $service->register('hardening.bad', stdClass::class))->toThrow(Exception::class);
    expect(fn () => $service->register('no_group', DummyAction::class))->toThrow(InvalidArgumentException::class);
});

it('stores no serialized subscription on the subscription update task', function () {
    $organization = hardeningOrganization();

    $action = new SubscriptionUpdate($organization, new SubscriptionService($organization));

    expect($action->customValues())->not->toHaveKey('subscription');
    expect($action->customValues())->toHaveKey('requested_at');

    $task = hardeningTask($organization, [
        'action_slug' => 'subscription_update',
        'custom_values' => ['subscription' => 'O:8:"stdClass":0:{}'],
    ]);

    // Retrying (e.g. a task created before this change) rebuilds from the database
    expect(SubscriptionUpdate::retry($task))->toBeInstanceOf(SubscriptionUpdate::class);
});

it('keeps the task dispatcher single-flight on one server', function () {
    Artisan::call('schedule:list');

    $events = collect(Schedule::events())->keyBy(fn ($event) => $event->description);

    expect($events->has('task-dispatcher'))->toBeTrue('task-dispatcher is not scheduled');
    expect($events['task-dispatcher']->withoutOverlapping)->toBeTrue('task-dispatcher can overlap itself');
    expect($events['task-dispatcher']->onOneServer)->toBeTrue('task-dispatcher runs on every server');
    // Not the 24 hour default, or one crashed run would stall every task
    expect($events['task-dispatcher']->expiresAt)->toBeLessThanOrEqual(60);
});

it('never lets the queue run a task twice and kills it before it is redelivered', function () {
    $organization = hardeningOrganization();
    $task = hardeningTask($organization);

    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);

    $job = new RunAction($task);

    expect($job->tries)->toBe(1);
    expect($job->timeout)->toBeLessThan(90);
});

it('gives long running actions a timeout inside the long queue retry_after', function () {
    $organization = hardeningOrganization();
    $task = hardeningTask($organization, ['action_slug' => 'application_update']);

    config(['queue.long_running_connection' => 'database-long']);

    $job = new RunAction($task);
    $retry_after = config('queue.connections.database-long.retry_after');

    expect($job->connection)->toBe('database-long');
    expect($job->timeout)->toBeLessThan($retry_after);
    expect($job->timeout)->toBeGreaterThan(config('queue.connections.database.retry_after'));
});

it('stores error messages longer than 255 characters', function () {
    $organization = hardeningOrganization();
    $message = str_repeat('helm error ', 200);

    $task = hardeningTask($organization, ['status' => 'failed', 'error_message' => $message]);

    expect($task->fresh()->error_message)->toBe($message);

    $column = collect(Schema::getColumns('tasks'))->firstWhere('name', 'error_message');
    expect($column['type_name'])->toBe('text');
});
