<?php

use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpgrade;
use App\Organization;
use App\Server;
use App\Support\Facades\Action;
use App\Task;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * Constructing an action stores what it was asked for. What it does to the app
 * (marking it updating or deleting, starting a job) happens once its task
 * exists, and again if the task is retried -- not each time something rebuilds
 * the action.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function pureConstructorInstance()
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    return test()->runActivate($plan, Organization::find(1), $app);
}

it('leaves the app alone until the upgrade task exists', function () {
    $instance = pureConstructorInstance();
    $tasks = Task::count();

    $action = new ApplicationUpgrade($instance, $instance->version);

    expect($instance->fresh()->status)->toBe('active');
    expect(Task::count())->toBe($tasks);

    Queue::fake();
    $task = Action::execute($action);

    expect($instance->fresh()->status)->toBe('updating');
    expect($task->version_id)->toBe($instance->version_id);
});

it('marks the app updating again when an upgrade is retried', function () {
    $instance = pureConstructorInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));

    $instance->status = 'active';
    $instance->save();

    Action::retry($task);

    expect($instance->fresh()->status)->toBe('updating');
});

it('leaves the app alone until the delete task exists', function () {
    $instance = pureConstructorInstance();

    $action = new ApplicationDelete($instance);

    expect($instance->fresh()->status)->toBe('active');

    Queue::fake();
    Action::execute($action);

    expect($instance->fresh()->status)->toBe('deleting');
});

it('leaves the app alone until the database migration task exists', function () {
    $instance = pureConstructorInstance();
    $target = Server::factory()->create(['name' => 'elsewhere-db', 'type' => 'database', 'interface' => 'app_database', 'host' => 'elsewhere.db', 'internal_address' => 'elsewhere.db.svc', 'default_web_server' => 0]);

    $action = new ApplicationDatabaseMigrate($instance, $target);

    expect($instance->fresh()->status)->toBe('active');

    Queue::fake();
    Action::execute($action);

    expect($instance->fresh()->status)->toBe('updating');
});

it('drops what the last run left, and keeps what the upgrade was made with, when it is retried', function () {
    $instance = pureConstructorInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationUpgrade($instance, $instance->version, notify: false));
    $previous = $task->getValue('previous_version_id');
    $task->custom_values = array_merge($task->customValues(), ['helm_pending_since' => now()->toIso8601String(), 'update_task_id' => 99]);
    $task->save();

    Action::retry($task);

    $fresh = $task->fresh();
    expect($fresh->custom_values)->toBe(['notify' => false, 'previous_version_id' => $previous]);
    expect($fresh->attempts)->toBe(1);
});

it('marks the app deleting again when a delete is retried, unless it has gained children', function () {
    $instance = pureConstructorInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationDelete($instance));

    $instance->status = 'active';
    $instance->save();
    Action::retry($task);

    expect($instance->fresh()->status)->toBe('deleting');

    // A child activated under it meanwhile
    $this->makeChildInstance($instance, 'active-child', 'active');
    $instance->status = 'active';
    $instance->save();

    Action::retry($task);

    expect($task->fresh()->status->value)->toBe('failed');
    expect($task->fresh()->error_message)->toBe(__('messages.exception.shared_app_has_children', ['app' => $instance->label]));
    expect($instance->fresh()->status)->toBe('active');
});

it('starts a database migration over from where it was going when it is retried', function () {
    $instance = pureConstructorInstance();
    $target = Server::factory()->create(['name' => 'elsewhere-db', 'type' => 'database', 'interface' => 'app_database', 'host' => 'elsewhere.db', 'internal_address' => 'elsewhere.db.svc', 'default_web_server' => 0]);
    Queue::fake();
    $task = Action::execute(new ApplicationDatabaseMigrate($instance, $target));
    $task->custom_values = array_merge($task->customValues(), ['backup_id' => 4, 'update_task_id' => 7]);
    $task->save();
    $instance->status = 'active';
    $instance->save();

    Action::retry($task);

    expect($task->fresh()->custom_values)->toBe(['target_server_id' => $target->id]);
    expect($instance->fresh()->status)->toBe('updating');
});
