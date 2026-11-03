<?php

namespace Tests\Feature\Admin;

use App\Organization;
use App\Support\Facades\AccountManager;
use App\Task;
use App\TaskEvent;
use App\TaskStep;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The admin task pages: one task's history, and the controls that follow the
 * task's state.
 */
class TasksTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        (new TestSupports)->seed();

        return User::find(1);
    }

    private function task(string $status = 'failed', array $attributes = []): Task
    {
        $task = new Task;
        $task->forceFill(array_merge([
            'organization_id' => Organization::find(1)->id,
            'action_group' => 'system',
            'action_slug' => 'dummy_action',
            'task_group' => 21,
            'status' => $status,
            'description' => 'Admin test task',
        ], $attributes));
        $task->save();

        return $task;
    }

    public function test_the_task_page_shows_its_history_steps_dependencies_and_values()
    {
        $admin = $this->admin();
        $dependency = $this->task('complete', ['description' => 'Needed first']);
        $waiting = $this->task('pending', ['description' => 'Needs this one']);
        $task = $this->task('failed', [
            'error_code' => 'throwable',
            'error_message' => 'It broke',
            'attempts' => 2,
            'custom_values' => ['plain' => 'visible', 'db_password' => 'hunter2', 'long' => str_repeat('a', 500)],
        ]);
        $task->dependOn($dependency);
        $waiting->dependOn($task);
        TaskEvent::record($task, 'started');
        TaskEvent::record($task, 'failed', 'It broke', ['code' => 'throwable']);
        $step = new TaskStep;
        $step->forceFill(['task_id' => $task->id, 'position' => 0, 'name' => 'prepare', 'status' => 'done', 'started_at' => now()->subSeconds(30), 'finished_at' => now()])->save();

        $this->actingAs($admin)
            ->get('/admin/server/tasks/'.$task->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/TaskView')
                ->where('task.id', $task->id)
                ->where('task.status', 'failed')
                ->where('task.error_message', 'It broke')
                ->where('task.can_retry', true)
                ->where('task.can_cancel', false)
                ->where('task.can_delete', true)
                ->has('events', 2)
                ->where('events.1.type', 'failed')
                ->where('events.1.context.code', 'throwable')
                ->has('steps', 1)
                ->where('steps.0.name', 'prepare')
                ->where('steps.0.seconds', 30)
                ->has('waiting_for', 1)
                ->where('waiting_for.0.id', $dependency->id)
                ->where('waiting_for.0.gate', 'start')
                ->has('waited_on_by', 1)
                ->where('waited_on_by.0.id', $waiting->id)
                ->where('values.plain', 'visible')
                ->where('values.db_password', '••••••••')
                ->where('values.long', fn ($long) => str_contains($long, '500 characters') && strlen($long) < 400));
    }

    public function test_the_task_page_is_for_admins_only()
    {
        $user = $this->admin();
        $task = $this->task();
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)->get('/admin/server/tasks/'.$task->id)->assertForbidden();
    }

    public function test_the_task_list_says_what_each_task_allows()
    {
        $admin = $this->admin();
        $this->task('failed');
        $this->task('in_progress');

        $response = $this->actingAs($admin)->postJson('/admin/server/tasks/api')->assertOk();

        $flags = collect($response->json('tasks'))->mapWithKeys(fn ($task) => [$task['status'] => [$task['can_retry'], $task['can_cancel'], $task['can_delete']]]);
        $this->assertSame([true, false, true], $flags['failed']);
        $this->assertSame([false, true, false], $flags['in_progress']);
    }

    public function test_the_task_list_can_filter_on_cancelled()
    {
        $admin = $this->admin();
        $this->task('cancelled');
        $this->task('failed');

        $this->actingAs($admin)->postJson('/admin/server/tasks/api', ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('tasks.0.status', 'cancelled');
    }

    public function test_a_failed_task_can_be_retried()
    {
        Queue::fake();
        $admin = $this->admin();
        $task = $this->task('failed');

        $this->actingAs($admin)->postJson('/admin/server/tasks/'.$task->id.'/retry')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotSame('failed', $task->fresh()->status->value);
        $this->assertTrue($task->events()->where('type', 'retried')->exists());
    }

    public function test_a_task_that_is_not_failed_cannot_be_retried()
    {
        $admin = $this->admin();
        $task = $this->task('in_progress');

        $this->actingAs($admin)->postJson('/admin/server/tasks/'.$task->id.'/retry')
            ->assertStatus(409)
            ->assertJsonPath('message', __('admin.tasks.not_retryable'));

        $this->assertSame('in_progress', $task->fresh()->status->value);
    }

    public function test_a_waiting_task_can_be_cancelled_but_not_a_running_one()
    {
        $admin = $this->admin();
        $waiting = $this->task('pending');
        $running = $this->task('in_progress', ['claimed_at' => now()]);

        $this->actingAs($admin)->postJson('/admin/server/tasks/'.$waiting->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->actingAs($admin)->postJson('/admin/server/tasks/'.$running->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('message', __('admin.tasks.running_now'));

        $this->assertSame('cancelled', $waiting->fresh()->status->value);
        $this->assertSame('in_progress', $running->fresh()->status->value);
    }

    public function test_only_a_task_that_is_over_can_be_deleted()
    {
        $admin = $this->admin();
        $over = $this->task('failed');
        $waiting = $this->task('pending');

        $this->actingAs($admin)->deleteJson('/admin/server/tasks/'.$over->id)->assertOk();
        $this->actingAs($admin)->deleteJson('/admin/server/tasks/'.$waiting->id)
            ->assertStatus(409)
            ->assertJsonPath('message', __('admin.tasks.not_deletable'));

        $this->assertNull(Task::find($over->id));
        $this->assertNotNull(Task::find($waiting->id));
    }

    public function test_bulk_actions_skip_what_a_task_does_not_allow_and_say_so()
    {
        Queue::fake();
        $admin = $this->admin();
        $failed = $this->task('failed');
        $running = $this->task('in_progress');

        $this->actingAs($admin)->postJson('/admin/server/tasks/bulk/retry', ['tasks' => [$failed->id, $running->id]])
            ->assertOk()
            ->assertJsonPath('done', 1)
            ->assertJsonPath('refused.'.$running->id, __('admin.tasks.not_retryable'))
            ->assertJsonPath('message', __('admin.tasks.bulk_result', ['done' => 1, 'refused' => 1]));

        $this->actingAs($admin)->postJson('/admin/server/tasks/bulk/cancel', ['tasks' => [$running->id]])->assertOk()->assertJsonPath('done', 1);
        $this->actingAs($admin)->postJson('/admin/server/tasks/bulk/delete', ['tasks' => [$running->id]])->assertOk()->assertJsonPath('done', 1);

        $this->assertNull(Task::find($running->id));
    }

    public function test_actions_that_change_things_are_no_longer_reachable_with_a_get()
    {
        $admin = $this->admin();
        $task = $this->task('failed');

        foreach (['/restart', '/delete', '/retry', '/cancel'] as $suffix) {
            $this->actingAs($admin)->get('/admin/server/tasks/'.$task->id.$suffix)->assertStatus($suffix === '/retry' || $suffix === '/cancel' ? 405 : 404);
        }
        foreach (['dummy', 'run_schedule', 'restart_queue'] as $name) {
            $this->actingAs($admin)->get('/admin/server/tasks/'.$name)->assertStatus(405);
        }

        $this->assertSame('failed', $task->fresh()->status->value);
    }

    public function test_the_dashboard_warns_about_blocked_tasks()
    {
        $admin = $this->admin();
        $this->task('blocked', ['blocked_from' => 'pending']);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('warnings', fn ($warnings) => collect($warnings)->contains(fn ($warning) => $warning['message'] === __('admin.dashboard.warning_messages.blocked_tasks', ['count' => 1]))));
    }
}
