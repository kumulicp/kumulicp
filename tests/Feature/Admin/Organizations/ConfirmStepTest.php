<?php

use App\Actions\ActionResult;
use App\Actions\Steps\AwaitConfirmation;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Organization;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\Steps\FakeAwaitConfirmation;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * A step that waits for an administrator to say something outside the control panel has been
 * done, and the app's page where they say so.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

afterEach(function () {
    $this->restoreServerInterfaces();
});

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();

    (new TestSupports)->seed();

    $this->admin = User::find(1);
    $this->organization = Organization::find(1);
    $this->appInstance = $this->activeDemoApp();
});

function confirmUrl($test): string
{
    return "/admin/organizations/{$test->organization->id}/apps/{$test->appInstance->id}";
}

/**
 * A task of the app, with the step having run on it.
 */
function taskWaitingOnAdmin($test, TaskStatus $status = TaskStatus::IN_PROGRESS): Task
{
    $task = Task::forceCreate([
        'organization_id' => $test->organization->id,
        'app_instance_id' => $test->appInstance->id,
        'action_slug' => 'application_web_move',
        'action_group' => 'system',
        'task_group' => 1,
        'description' => 'Moving',
        'status' => $status,
        'custom_values' => ['target_server_id' => 5],
    ]);

    $task->custom_values = array_merge($task->customValues(), (new FakeAwaitConfirmation)->run($task)->values);
    $task->save();

    return $task->fresh();
}

it('waits, saying what it waits for on the task, until it is confirmed', function () {
    $task = taskWaitingOnAdmin($this);

    $step = new FakeAwaitConfirmation;
    expect($step->check($task)->outcome)->toBe(ActionResult::WAIT)
        ->and($task->fresh()->error_message)->toBe('Restore the volume, then continue')
        ->and($task->getValue('awaiting'))->toBe(['class' => FakeAwaitConfirmation::class, 'message' => 'Restore the volume, then continue'])
        // However long it takes
        ->and($step->timeoutMinutes())->toBeNull();

    FakeAwaitConfirmation::confirm($task, ['claim' => 'restored-data']);
    $result = $step->check($task->fresh());

    expect($result->outcome)->toBe(ActionResult::DONE)
        ->and($result->values)->toBe(['awaiting' => null])
        ->and(FakeAwaitConfirmation::confirmation($task->fresh())['values'])->toBe(['claim' => 'restored-data']);
});

it('keeps what else is on the task when it is confirmed', function () {
    $task = taskWaitingOnAdmin($this);

    FakeAwaitConfirmation::confirm($task);

    expect($task->fresh()->getValue('target_server_id'))->toBe(5)
        ->and(FakeAwaitConfirmation::confirmation($task->fresh()))->toHaveKey('at');
});

it('finds the app\'s task that is waiting, and not one that was told or has finished', function () {
    $task = taskWaitingOnAdmin($this);

    expect(AwaitConfirmation::waiting($this->appInstance)?->id)->toBe($task->id);

    FakeAwaitConfirmation::confirm($task);
    expect(AwaitConfirmation::waiting($this->appInstance))->toBeNull();

    $finished = taskWaitingOnAdmin($this, TaskStatus::COMPLETE);
    expect(AwaitConfirmation::waiting($this->appInstance))->toBeNull();
});

it('puts what is waiting on the app\'s edit page', function () {
    $this->actingAs($this->admin)->get(confirmUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page->where('awaiting', null));

    $task = taskWaitingOnAdmin($this);

    $this->actingAs($this->admin)->get(confirmUrl($this).'/edit')
        ->assertInertia(fn ($page) => $page
            ->where('awaiting.task_id', $task->id)
            ->where('awaiting.message', 'Restore the volume, then continue'));
});

it('confirms with what the step asks for', function () {
    $task = taskWaitingOnAdmin($this);

    $this->actingAs($this->admin)
        ->post(confirmUrl($this).'/confirm-step', ['task_id' => $task->id, 'claim' => 'restored-data'])
        ->assertRedirect(confirmUrl($this))
        ->assertSessionHas('success');

    expect(FakeAwaitConfirmation::confirmation($task->fresh())['values'])->toBe(['claim' => 'restored-data']);
});

it('does not confirm without what the step asks for', function () {
    $task = taskWaitingOnAdmin($this);

    $this->actingAs($this->admin)
        ->post(confirmUrl($this).'/confirm-step', ['task_id' => $task->id])
        ->assertSessionHasErrors('claim');

    expect(FakeAwaitConfirmation::confirmation($task->fresh()))->toBeNull();
});

it('does not confirm what is not waiting', function () {
    $task = taskWaitingOnAdmin($this);
    FakeAwaitConfirmation::confirm($task);

    $this->actingAs($this->admin)
        ->post(confirmUrl($this).'/confirm-step', ['task_id' => $task->id, 'claim' => 'x'])
        ->assertSessionHas('error', __('admin.organizations.apps.denied.nothing_to_confirm'));

    $this->actingAs($this->admin)
        ->post(confirmUrl($this).'/confirm-step', ['task_id' => 999999, 'claim' => 'x'])
        ->assertSessionHas('error', __('admin.organizations.apps.denied.nothing_to_confirm'));
});

it('does not confirm through another organization', function () {
    $task = taskWaitingOnAdmin($this);
    $other = Organization::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/confirm-step", ['task_id' => $task->id, 'claim' => 'x'])
        ->assertNotFound();

    expect(FakeAwaitConfirmation::confirmation($task->fresh()))->toBeNull();
});
