<?php

use App\Actions\Apps\ApplicationDelete;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * An app whose site is made by a job inside a parent's release (ERPNext): its
 * own web interface reports the parent's release, which stays deployed, so
 * the delete is done when the job that dropped the site is, not when the
 * release goes inactive.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    DemoAppProfile::$job_activation = false;
    $this->restoreServerInterfaces();
});

it('is deleted once its drop-site job is done, though the release it lives in is still deployed', function () {
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $instance = $this->runActivate($plan, Organization::find(1), $app);
    $instance_id = $instance->id;

    // Past handle(): the drop-site job it waited for is complete
    $task = Action::execute(new ApplicationDelete($instance));
    $task->status = TaskStatus::IN_PROGRESS;
    $task->save();

    // The release the site lives in is still deployed: the fake remembers the delete handle() made
    FakeServerManager::reset();
    DemoAppProfile::$job_activation = true;

    app(TaskRunner::class)->poll($task);

    expect(AppInstance::find($instance_id))->toBeNull();
});
