<?php

use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Apps\WebMove\AwaitVolume;
use App\Actions\Steps\AwaitConfirmation;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Integrations\Applications\Nextcloud\NextcloudProfile;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Task;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\TestSupports;

/**
 * Moving an app to another web server: stop it where it is, wait for its volume to be restored
 * on the new cluster, install it there against that volume, check it is running, refresh the
 * DNS -- and back to the old server, scaled up again, if any of that fails. The cluster is
 * FakeServerManager's; what is under test is the sequencing, the volume handover and the rollback.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
    // The chart can be given a volume that exists, unless a test says it can't
    DemoAppProfile::$storage_handover = (new NextcloudProfile)->storageHandover(new AppInstance);
});

afterEach(function () {
    DemoAppProfile::$storage_handover = null;
    $this->restoreServerInterfaces();
});

function webServer(string $name, string $ip, array $attributes = []): Server
{
    return Server::factory()->create($attributes + [
        'name' => $name,
        'type' => 'web',
        'interface' => 'rancher',
        'host' => "{$name}.example.com",
        'ip' => $ip,
        'default_web_server' => 0,
    ]);
}

/**
 * An active app on a cluster called "old-cluster".
 *
 * @return array{AppInstance, OrgServer}
 */
function appOnWebServer(): array
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);
    $instance = test()->runActivate($plan, $org, $app);

    $old = OrgServer::forceCreate(['organization_id' => $org->id, 'server_id' => webServer('old-cluster', '10.0.0.1')->id]);

    $instance->web_server_id = $old->id;
    $instance->status = 'active';
    $instance->save();

    FakeServerManager::reset();

    return [$instance->fresh(), $old];
}

function startWebMove(AppInstance $instance, Server $target): Task
{
    return Action::execute(new ApplicationWebMove($instance, $target))->fresh();
}

/**
 * Runs what the task is waiting on, then the task, a few times over: as far as it gets, which is the
 * end, or the step that waits for an administrator.
 */
function advanceWebMove(Task $task, int $rounds = 8): Task
{
    for ($round = 0; $round < $rounds; $round++) {
        if (in_array($task->fresh()->status, [TaskStatus::COMPLETE, TaskStatus::FAILED])) {
            break;
        }

        Task::where('task_group', $task->task_group)
            ->where('id', '!=', $task->id)
            ->whereIn('status', TaskStatus::openValues())
            ->get()
            ->each(fn (Task $other) => app(TaskRunner::class)->tryPoll($other));

        app(TaskRunner::class)->poll($task);
    }

    return $task->fresh();
}

it('stops the app and waits for its volume to be restored on the new cluster', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');

    $task = advanceWebMove(startWebMove($instance, $target));

    // Stopped where it is, and waiting for the volume to be restored on the new cluster
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS)
        ->and($instance->fresh()->setting('quiesced_for_move'))->toBeTrue()
        ->and($instance->fresh()->web_server_id)->toBe($old->id)
        ->and(AwaitConfirmation::waiting($instance)?->id)->toBe($task->id)
        ->and(Task::where('action_slug', 'application_update')->where('task_group', $task->task_group)->first()->getValue('steps'))->toBe(['release']);
});

it('scales the old release back up when the app cannot be stopped', function () {
    [$instance, $old] = appOnWebServer();

    $task = startWebMove($instance, webServer('new-cluster', '10.0.0.2'));
    $stop = Task::find($task->getValue('quiesce_update_task_id'));
    $stop->status = TaskStatus::FAILED;
    $stop->error_message = 'helm upgrade failed';
    $stop->save();
    app(TaskRunner::class)->poll($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED)
        ->and($instance->fresh()->web_server_id)->toBe($old->id)
        ->and($instance->fresh()->setting('quiesced_for_move'))->toBeFalse();

    // Never pointed anywhere else, so nothing but this puts the release back
    $again = Task::where('action_slug', 'application_update')->where('id', '>', $stop->id)->sole();
    expect($again->getValue('steps'))->toBe(['release']);
});

it('only offers a new volume to an app whose chart cannot be given one that exists', function () {
    [$instance] = appOnWebServer();
    $task = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2')));
    $existing = ['volume' => 'existing', 'claim' => 'restored-data'];

    expect(Validator::make($existing, AwaitVolume::rules($task))->passes())->toBeTrue();

    DemoAppProfile::$storage_handover = null;

    expect(Validator::make($existing, AwaitVolume::rules($task))->fails())->toBeTrue()
        ->and(Validator::make(['volume' => 'new'], AwaitVolume::rules($task))->passes())->toBeTrue();
});

it('lists everything in the way of the move at once', function () {
    [$instance, $old] = appOnWebServer();
    $other_kind = webServer('direct-cluster', '10.0.0.3', ['interface' => 'kubernetes']);

    expect(ApplicationWebMove::blockers($instance, $other_kind))->toBe([
        __('messages.web_move.other_driver', ['server' => 'old-cluster', 'driver' => 'rancher']),
    ]);

    $instance->status = 'updating';

    expect(ApplicationWebMove::blockers($instance, $other_kind))->toBe([
        __('messages.server_move.app_not_active', ['type' => 'web']),
        __('messages.web_move.other_driver', ['server' => 'old-cluster', 'driver' => 'rancher']),
    ])->and(ApplicationWebMove::blockers($instance, $old->server))->toBe([
        __('messages.server_move.app_not_active', ['type' => 'web']),
        __('messages.server_move.same_server', ['type' => 'web']),
    ]);
});
