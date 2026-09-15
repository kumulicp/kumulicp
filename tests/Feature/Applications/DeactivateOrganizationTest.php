<?php

use App\Actions\Organizations\DeactivateOrganization;
use App\Notifications\ApplicationDeactivated;
use App\Notifications\OrganizationDeactivated;
use App\Organization;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * DeactivateOrganization fans out one ApplicationDeactivate task per app
 * instance the org has, then waits on all of them (via 'waiting_for')
 * before completing itself. This is the only place ApplicationDeactivate
 * is actually invoked in the app today.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

it('deactivates the organization and all of its app instances', function () {
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);

    $instance = $this->runActivate($plan, $org, $app);

    $task = Action::execute(new DeactivateOrganization($org));
    $task->refresh();

    $org->refresh();
    expect($org->status)->toBe('deactivated');

    $waitingFor = $task->getValue('waiting_for');
    expect($waitingFor)->toHaveCount(1);

    $appTask = Task::find($waitingFor[0]);
    expect($appTask->action_slug)->toBe('application_deactivate');

    // Not complete yet -- still waiting on the fanned-out app task.
    Action::complete($task);
    $task->refresh();
    expect($task->status)->not->toBe('complete');

    FakeServerManager::markInactive($instance->id);
    Action::complete($appTask);
    $instance->refresh();
    expect($instance->status)->toBe('deactivated');

    Action::complete($task);
    $task->refresh();

    expect($task->status)->toBe('complete');

    $admin = User::where('username', 'demo')->firstOrFail();
    Notification::assertSentTo($admin, ApplicationDeactivated::class);
    Notification::assertSentTo($admin, OrganizationDeactivated::class);
});

it('fans out a deactivate task for every app instance the organization has', function () {
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);

    $instance1 = $this->runActivate($plan, $org, $app);

    // A second, independent instance of the same app/org -- built directly
    // rather than through prepareDemoApp()+runActivate() again, since
    // prepareDemoApp() unconditionally inserts a fresh 'demo_app' Application
    // row and would collide on the unique slug.
    $instance2 = $this->makeChildInstance($instance1, 'second-instance');
    $instance2->parent_id = null;
    $instance2->save();

    $task = Action::execute(new DeactivateOrganization($org));
    $task->refresh();

    expect($task->getValue('waiting_for'))->toHaveCount(2);

    $instance1->refresh();
    $instance2->refresh();
    expect($instance1->status)->toBe('deactivating');
    expect($instance2->status)->toBe('deactivating');
});
