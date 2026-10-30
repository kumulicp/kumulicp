<?php

namespace Tests\Support\Concerns;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Enums\TaskStatus;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Task;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Shared lifecycle assertions for Application* action tests.
 *
 * Supports both fake (no sleep, synchronous) and real-server (with sleep)
 * modes via $lifecycleSleepSeconds and $lifecycleMaxIterations.
 *
 * Fake mode (default): completes on the first complete() call.
 * Real mode: set $lifecycleSleepSeconds = 10 and raise $lifecycleMaxIterations.
 *
 * Usage:
 *   use Tests\Support\Concerns\TestsApplicationLifecycle;
 *   use Tests\Support\Concerns\TestsWithServerInterfaces;
 *
 *   class MyAppTest extends TestCase
 *   {
 *       use RefreshDatabase, TestsApplicationLifecycle, TestsWithServerInterfaces;
 *
 *       protected function setUp(): void
 *       {
 *           parent::setUp();
 *           $this->setupFakeServerInterfaces();
 *       }
 *
 *       public function test_lifecycle(): void
 *       {
 *           $support = new TestSupports;
 *           $support->seed();
 *           $org = Organization::find(1);
 *           $app = Application::where('slug', 'demo_app')->first();
 *           $plan = AppPlan::factory()->create([...]);
 *
 *           $instance = $this->runActivate($plan, $org, $app);
 *           $this->runUpdate($instance);
 *           $this->runUpgrade($instance);
 *           $this->runDelete($instance);
 *       }
 *   }
 */
trait TestsApplicationLifecycle
{
    protected int $lifecycleMaxIterations = 5;

    protected int $lifecycleSleepSeconds = 0;

    protected function fakeNotificationsAndMail(): void
    {
        Notification::fake();
        Mail::fake();
    }

    /**
     * AppInstance doesn't use HasFactory (and is fully guarded by default),
     * so a child instance for parent/child cascade or shared-app-hub tests
     * is built by hand rather than via AppInstance::factory()/::create().
     */
    protected function makeChildInstance(AppInstance $parent, string $name, string $status = 'active'): AppInstance
    {
        $child = new AppInstance;
        $child->application_id = $parent->application_id;
        $child->organization_id = $parent->organization_id;
        $child->version_id = $parent->version_id;
        $child->plan_id = $parent->plan_id;
        $child->parent_id = $parent->id;
        $child->name = $name;
        $child->label = $name;
        $child->api_password = '';
        $child->status = $status;
        $child->save();

        return $child;
    }

    protected function pollUntilDone(Task &$task, callable $completeCallback): void
    {
        $iterations = 0;
        while ($iterations < $this->lifecycleMaxIterations) {
            $completeCallback($task);
            $fresh = $task->fresh();
            if ($fresh !== null) {
                $task = $fresh;
            }
            if (in_array($task->status, [TaskStatus::COMPLETE, TaskStatus::FAILED], true)) {
                return;
            }
            if ($this->lifecycleSleepSeconds > 0) {
                sleep($this->lifecycleSleepSeconds);
            }
            $iterations++;
        }

        $this->fail("Task {$task->id} did not complete after {$this->lifecycleMaxIterations} polls (status: {$task->status->value}).");
    }

    protected function runActivate(AppPlan $plan, Organization $org, Application $app): AppInstance
    {
        $task = Action::execute(new ApplicationActivate(organization: $org, app: $app, plan: $plan));
        $task->status = TaskStatus::IN_PROGRESS;
        $task->save();
        app(TaskRunner::class)->execute($task);

        $this->pollUntilDone($task, fn (Task &$t) => app(TaskRunner::class)->poll($t));

        $this->assertSame(TaskStatus::COMPLETE, $task->status, 'ApplicationActivate did not complete');

        $instance = AppInstance::where('application_id', $app->id)->first();
        $this->assertNotNull($instance, 'No AppInstance found after activation');
        $this->assertEquals('active', $instance->status, 'AppInstance status is not active after activation');

        return $instance;
    }

    protected function runUpdate(AppInstance $instance): void
    {
        $task = Action::execute(new ApplicationUpdate($instance));
        app(TaskRunner::class)->execute($task);

        $this->pollUntilDone($task, fn (Task &$t) => app(TaskRunner::class)->poll($t));

        $instance->refresh();
        $this->assertSame(TaskStatus::COMPLETE, $task->status, 'ApplicationUpdate did not complete');
        $this->assertEquals('active', $instance->status, 'AppInstance status is not active after update');
    }

    protected function runUpgrade(AppInstance $instance): void
    {
        $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));
        app(TaskRunner::class)->execute($task);

        $this->pollUntilDone($task, fn (Task &$t) => app(TaskRunner::class)->poll($t));

        $instance->refresh();
        $this->assertSame(TaskStatus::COMPLETE, $task->status, 'ApplicationUpgrade did not complete');
        $this->assertEquals('active', $instance->status, 'AppInstance status is not active after upgrade');
    }

    protected function runDelete(AppInstance $instance): void
    {
        $instanceId = $instance->id;
        $task = Action::execute(new ApplicationDelete($instance));
        app(TaskRunner::class)->execute($task);
        $task->status = TaskStatus::IN_PROGRESS;
        $task->save();

        $this->pollUntilDone($task, function (Task &$t) {
            app(TaskRunner::class)->poll($t);
            // ApplicationDelete marks itself done by deleting the Task record
            if (is_null(Task::find($t->id))) {
                $t->status = TaskStatus::COMPLETE;
            }
        });

        $this->assertNull(AppInstance::find($instanceId), 'AppInstance still exists after deletion');
    }
}
