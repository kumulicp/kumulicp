<?php

namespace Tests\Support\Concerns;

use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Organization;
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

    protected function pollUntilDone(Task &$task, callable $completeCallback): void
    {
        $iterations = 0;
        while ($iterations < $this->lifecycleMaxIterations) {
            $completeCallback($task);
            $fresh = $task->fresh();
            if ($fresh !== null) {
                $task = $fresh;
            }
            if (in_array($task->status, ['complete', 'failed'])) {
                return;
            }
            if ($this->lifecycleSleepSeconds > 0) {
                sleep($this->lifecycleSleepSeconds);
            }
            $iterations++;
        }

        $this->fail("Task {$task->id} did not complete after {$this->lifecycleMaxIterations} polls (status: {$task->status}).");
    }

    protected function runActivate(AppPlan $plan, Organization $org, Application $app): AppInstance
    {
        $task = Action::execute(new ApplicationActivate(organization: $org, app: $app, plan: $plan));
        $task->status = 'in_progress';
        $task->save();
        Action::run($task);

        $this->pollUntilDone($task, fn (Task &$t) => Action::complete($t));

        $this->assertEquals('complete', $task->status, 'ApplicationActivate did not complete');

        $instance = AppInstance::where('application_id', $app->id)->first();
        $this->assertNotNull($instance, 'No AppInstance found after activation');
        $this->assertEquals('active', $instance->status, 'AppInstance status is not active after activation');

        return $instance;
    }

    protected function runUpdate(AppInstance $instance): void
    {
        $task = Action::execute(new ApplicationUpdate($instance));
        Action::run($task);

        $this->pollUntilDone($task, fn (Task &$t) => Action::complete($t));

        $instance->refresh();
        $this->assertEquals('complete', $task->status, 'ApplicationUpdate did not complete');
        $this->assertEquals('active', $instance->status, 'AppInstance status is not active after update');
    }

    protected function runUpgrade(AppInstance $instance): void
    {
        $task = Action::execute(new ApplicationUpgrade($instance, $instance->version));
        Action::run($task);

        $this->pollUntilDone($task, fn (Task &$t) => Action::complete($t));

        $instance->refresh();
        $this->assertEquals('complete', $task->status, 'ApplicationUpgrade did not complete');
        $this->assertEquals('active', $instance->status, 'AppInstance status is not active after upgrade');
    }

    protected function runDelete(AppInstance $instance): void
    {
        $instanceId = $instance->id;
        $task = Action::execute(new ApplicationDelete($instance));
        Action::run($task);
        $task->status = 'in_progress';
        $task->save();

        $this->pollUntilDone($task, function (Task &$t) {
            Action::complete($t);
            // ApplicationDelete marks itself done by deleting the Task record
            if (is_null(Task::find($t->id))) {
                $t->status = 'complete';
            }
        });

        $this->assertNull(AppInstance::find($instanceId), 'AppInstance still exists after deletion');
    }
}
