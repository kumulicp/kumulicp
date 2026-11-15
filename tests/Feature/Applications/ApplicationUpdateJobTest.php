<?php

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationUpdateJob;
use App\Enums\TaskStatus;
use App\Task;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();

    // An app whose sites are made by jobs. DemoJobs has no upgrade or update_settings job.
    DemoAppProfile::$job_activation = true;

    $this->instance = $support->demo_app->instances()->first();
});

afterEach(function () {
    DemoAppProfile::$job_activation = false;
});

function jobTask($instance, string $job, string $status = 'ready'): Task
{
    $task = new Task;
    $task->organization_id = $instance->organization_id;
    $task->application_id = $instance->application_id;
    $task->app_instance_id = $instance->id;
    $task->action_slug = 'application_update_job';
    $task->action_group = 'default';
    $task->task_group = rand();
    $task->description = "Test {$job}";
    $task->status = $status;
    $task->custom_values = ['job_name' => $job];
    $task->save();

    return $task;
}

it('fails a lifecycle job that has no job registered, instead of reporting it done', function () {
    $task = jobTask($this->instance, 'upgrade');

    ApplicationUpdateJob::run($task);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::FAILED)
        ->and($task->error_message)->toContain('upgrade');
});

it('lets an optional job with nothing registered count as done', function () {
    $task = jobTask($this->instance, 'update_settings');

    ApplicationUpdateJob::run($task);

    expect(Task::find($task->id))->toBeNull();
});

it('does not fail a lifecycle job for an app that is not run by jobs', function () {
    DemoAppProfile::$job_activation = false;
    $task = jobTask($this->instance, 'upgrade');

    ApplicationUpdateJob::run($task);

    expect(Task::find($task->id))->toBeNull();
});

it('waits for the open task already doing the job instead of for nothing', function () {
    $open = jobTask($this->instance, 'activate', 'in_progress');
    jobTask($this->instance, 'upgrade', 'in_progress');
    jobTask($this->instance, 'activate', 'complete');

    $find = new ReflectionMethod(ApplicationActivate::class, 'openTask');

    expect($find->invoke(null, 'application_update_job', $this->instance, 'activate')?->id)->toBe($open->id)
        ->and($find->invoke(null, 'application_update_job', $this->instance, 'deactivate'))->toBeNull()
        ->and($find->invoke(null, 'application_upgrade', $this->instance))->toBeNull();
});
