<?php

use App\Actions\Apps\ApplicationActivate;
use App\AppInstance;
use App\AppPlan;
use App\Enums\TaskStatus;
use App\Events\Apps\ApplicationPreActivation;
use App\Jobs\RunAction;
use App\Notifications\ApplicationActivated;
use App\Organization;
use App\Server;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * Activating an app: the instance is made once the task exists (not by
 * constructing the action), exactly once however often the task is retried,
 * and the task finishes when the app is up on the server.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function activationFixture(): array
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    return [$app, $plan, Organization::find(1)];
}

/** A task for a new activation that has been created but not run */
function activationTask(?AppPlan $plan = null, array $customizations = [], ?string $label = null): Task
{
    [$app, $default_plan, $org] = activationFixture();

    Queue::fake();

    return Action::execute(new ApplicationActivate($org, $app, $plan ?? $default_plan, $customizations, label: $label));
}

it('creates nothing when the action is constructed', function () {
    [$app, $plan, $org] = activationFixture();
    Event::fake([ApplicationPreActivation::class]);
    $instances = AppInstance::count();
    $tasks = Task::count();

    new ApplicationActivate($org, $app, $plan);
    new ApplicationActivate($org, $app, $plan, null);

    expect(AppInstance::count())->toBe($instances);
    expect(Task::count())->toBe($tasks);
    Event::assertNotDispatched(ApplicationPreActivation::class);
});

it('creates the app instance once the task exists, and links it to the task', function () {
    [$app, $plan, $org] = activationFixture();
    Event::fake([ApplicationPreActivation::class]);
    Queue::fake();
    $instances = AppInstance::count();

    $task = Action::execute(new ApplicationActivate($org, $app, $plan, label: 'My site'));

    expect(AppInstance::count())->toBe($instances + 1);
    expect($task->app_instance_id)->not->toBeNull();
    expect($task->getValue('app_instance_id'))->toBe($task->app_instance_id);
    expect($task->app_instance->label)->toBe('My site');
    expect($task->app_instance->status)->toBe('activating');
    expect($task->getValue('app_plan'))->toBe($plan->id);
    expect($task->fresh()->attempts)->toBe(0);
    Event::assertDispatchedTimes(ApplicationPreActivation::class, 1);
});

it('keeps the customizations in the task as the request sent them', function () {
    $task = activationTask(customizations: ['custom_option' => 'value']);

    expect($task->getValue('custom_option'))->toBe('value');
    expect($task->getValue('app_plan'))->not->toBeNull();
});

it('makes the task wait for the single sign-on setup when the plan has an SSO server', function () {
    [$app, $plan, $org] = activationFixture();
    $plan->sso_server_id = Server::first()->id;
    $plan->save();
    Queue::fake();

    $task = Action::execute(new ApplicationActivate($org, $app, $plan));

    $sso = Task::find($task->getValue('sso_task_id'));
    expect($sso->action_slug)->toBe('application_sso_setup');
    expect($sso->app_instance_id)->toBe($task->app_instance_id);
    expect($task->dependencies()->wherePivot('gate', Task::GATE_START)->pluck('tasks.id')->all())->toBe([$sso->id]);
});

it('does not make a second app instance, or fire the event again, when the task is retried', function () {
    [$app, $plan, $org] = activationFixture();
    Event::fake([ApplicationPreActivation::class]);
    Queue::fake();
    $task = Action::execute(new ApplicationActivate($org, $app, $plan));
    $instances = AppInstance::count();

    Action::retry($task);

    expect(AppInstance::count())->toBe($instances);
    expect($task->fresh()->app_instance_id)->toBe($task->app_instance_id);
    Event::assertDispatchedTimes(ApplicationPreActivation::class, 1);
});

it('makes an app instance for each request, even identical ones', function () {
    [$app, $plan, $org] = activationFixture();
    Queue::fake();
    $instances = AppInstance::count();

    $first = Action::execute(new ApplicationActivate($org, $app, $plan, label: 'Same'));
    $second = Action::execute(new ApplicationActivate($org, $app, $plan, label: 'Same'));

    expect($second)->not->toBeNull();
    expect($second->app_instance_id)->not->toBe($first->app_instance_id);
    expect(AppInstance::count())->toBe($instances + 2);
});

it('starts an app on a shared plan in progress, and puts it back there when retried', function () {
    [$app, $plan, $org] = activationFixture();
    $hub = $this->runActivate($plan, $org, $app);
    $shared_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'web_server_id' => 1,
        'shared_app_id' => $hub->id,
        'settings' => array_merge($plan->settings, ['server_type' => 'shared']),
    ]);
    Queue::fake();

    $task = Action::execute(new ApplicationActivate($org, $app, $shared_plan));

    expect($task->getValue('shared_app'))->toBeTrue();
    expect($task->app_instance->parent_id)->toBe($hub->id);
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS);

    Action::retry($task);

    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
});

it('makes the app a child of the parent it is activated under, and waits for that parent to be active', function () {
    [$app, $plan, $org] = activationFixture();
    $parent = $this->runActivate($plan, $org, $app);
    Queue::fake();

    $task = Action::execute(new ApplicationActivate($org, $app, $plan, [], $parent));

    expect($task->app_instance->parent_id)->toBe($parent->id);
    expect(json_decode($task->fresh()->prerequisites, true)['prereqs'])->toContain(['type' => 'application_required', 'app_id' => $parent->id]);
});

it('has no parent, and nothing to wait for, when none is given and the plan is not shared', function () {
    $task = activationTask();

    expect($task->app_instance->parent_id)->toBeNull();
    expect(collect(json_decode($task->fresh()->prerequisites, true)['prereqs'])->pluck('type')->all())->not->toContain('application_required');
});

it('fails the task, and keeps the one app instance, when something goes wrong creating it', function () {
    [$app, $plan, $org] = activationFixture();
    Queue::fake();
    Event::listen(ApplicationPreActivation::class, function () {
        throw new RuntimeException('a listener broke');
    });
    $instances = AppInstance::count();

    $task = Action::execute(new ApplicationActivate($org, $app, $plan));

    expect($task->status)->toBe(TaskStatus::FAILED);
    expect($task->error_message)->toBe('a listener broke');
    expect(AppInstance::count())->toBe($instances + 1);
    expect($task->app_instance_id)->not->toBeNull();

    // Retrying carries on with that instance rather than making another
    Action::retry($task);

    expect(AppInstance::count())->toBe($instances + 1);
    expect($task->fresh()->status)->not->toBe(TaskStatus::FAILED);
});

it('runs: queues the customizations and waits for the app to come up', function () {
    $task = activationTask();

    (new RunAction($task))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);
    $customizations = Task::where('action_slug', 'process_customizations')->sole();
    expect($customizations->app_instance_id)->toBe($task->app_instance_id);
    expect($customizations->dependencies()->wherePivot('gate', Task::GATE_START)->pluck('tasks.id')->all())->toBe([$task->id]);
});

it('has no customizations to process for a container that has no site, and completes once it is up', function () {
    [$app, $plan, $org] = activationFixture();
    $plan->updateSettings(['container_only' => true]);
    $plan->save();
    Queue::fake();
    $task = Action::execute(new ApplicationActivate($org, $app, $plan));

    (new RunAction($task))->handle();

    expect(Task::where('action_slug', 'process_customizations')->exists())->toBeFalse();

    app(TaskRunner::class)->poll($task->fresh());

    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
    expect($task->app_instance->fresh()->status)->toBe('active');
});

// Connecting to the server makes the organization when it is not there (ServerInterfaceService::checkConnection(),
// which waits for it), so by the time the activation looks, it exists and the activation goes on
it('creates the organization on the server when connecting, and carries on', function () {
    $task = activationTask();
    FakeServerManager::missingOrganization();

    (new RunAction($task))->handle();

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($fresh->error_message)->toBeNull();
    expect(Application::instance($task->app_instance)->connect('web')->existsOrganization())->toBeTrue();
    expect(Task::where('action_slug', 'process_customizations')->count())->toBe(1);
});

it('fails when the app is not on the server', function () {
    $task = activationTask();
    (new RunAction($task))->handle();
    FakeServerManager::markMissing($task->app_instance_id);

    app(TaskRunner::class)->poll($task->fresh());

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::FAILED);
    expect($fresh->error_code)->toBe('app_not_found');
    expect($fresh->error_message)->toContain($task->application->name);
});

it('keeps waiting while the app is not up yet', function () {
    $task = activationTask();
    (new RunAction($task))->handle();
    FakeServerManager::markInactive($task->app_instance_id);

    app(TaskRunner::class)->poll($task->fresh());

    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($task->app_instance->fresh()->status)->not->toBe('active');
});

it('completes once the app is up: the app is active and the admins are told', function () {
    [$app, $plan, $org] = activationFixture();
    Queue::fake();
    $task = Action::execute(new ApplicationActivate($org, $app, $plan));
    (new RunAction($task))->handle();

    app(TaskRunner::class)->poll($task->fresh());

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::COMPLETE);
    expect($task->app_instance->fresh()->status)->toBe('active');
    $admin = $org->admins()->first()->get();
    Notification::assertSentTo($admin, ApplicationActivated::class);
    expect(Task::where('action_slug', 'subscription_update')->exists())->toBeTrue();
});
