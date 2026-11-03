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
