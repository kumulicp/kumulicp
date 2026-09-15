<?php

use App\Actions\Apps\ApplicationDeactivate;
use App\AdditionalStorage;
use App\AppInstance;
use App\Notifications\ApplicationDeactivated;
use App\Organization;
use App\Support\Facades\Action;
use App\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * ApplicationDeactivate is only reachable today via DeactivateOrganization
 * (org-wide deactivation) -- the per-app "remove" flow in
 * Account\Applications::destroy() takes a different path entirely (sets
 * deactivate_at and lets DeactivatedAppInstanceCheck delete it later). This
 * covers ApplicationDeactivate directly so its own behavior has a contract
 * independent of whichever caller reaches it.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function deactivateTestInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);

    return test()->runActivate($plan, $org, $app);
}

it('deactivates an active app instance and notifies admins', function () {
    $instance = deactivateTestInstance();

    $task = Action::execute(new ApplicationDeactivate($instance));

    // ApplicationDeactivate is long_running, so execute()'s own dispatch
    // just enqueues onto the long-running connection -- run it explicitly,
    // matching the pattern the shared lifecycle trait uses.
    $instance->refresh();
    expect($instance->status)->toBe('deactivating');

    Action::run($task);
    $task->refresh();

    // ApplicationDeactivate::complete() only finalizes once the web server
    // reports inactive -- FakeServerManager defaults to "active" for any
    // instance it hasn't been told otherwise about.
    FakeServerManager::markInactive($instance->id);
    Action::complete($task);
    $task->refresh();

    expect($task->status)->toBe('complete');

    $instance->refresh();
    expect($instance->status)->toBe('deactivated');

    $admin = User::where('username', 'demo')->firstOrFail();
    Notification::assertSentTo($admin, ApplicationDeactivated::class);
});

it('cascades deactivation to child app instances', function () {
    $instance = deactivateTestInstance();

    $child = $this->makeChildInstance($instance, 'child-instance');

    $task = Action::execute(new ApplicationDeactivate($instance));

    $child->refresh();
    expect($child->status)->toBe('deactivating');

    Action::run($task);
    $task->refresh();

    FakeServerManager::markInactive($instance->id);
    Action::complete($task);

    $instance->refresh();
    $child->refresh();

    expect($instance->status)->toBe('deactivated');
    expect($child->status)->toBe('deactivated');
});

it('removes additional storage records once deactivation completes', function () {
    $instance = deactivateTestInstance();

    $storage = new AdditionalStorage;
    $storage->organization_id = $instance->organization_id;
    $storage->app_instance_id = $instance->id;
    $storage->entity = 'user';
    $storage->name = 'testing1';
    $storage->quantity = 1;
    $storage->save();

    $task = Action::execute(new ApplicationDeactivate($instance));
    Action::run($task);
    $task->refresh();

    FakeServerManager::markInactive($instance->id);
    Action::complete($task);

    expect(AdditionalStorage::find($storage->id))->toBeNull();
});
