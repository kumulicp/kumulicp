<?php

use App\Actions\Apps\ApplicationSsoMove;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Task;
use App\TaskStep;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\SSO\FakeSSO;
use Tests\Support\TestSupports;

/**
 * Moving an app's sign-in to another single sign-on server: the old settings are saved and
 * cleared, the new server is set up from nothing, the standard update gives the app its new
 * address and secret -- and the old server and settings come back if any of that fails. The
 * Authentik calls are FakeSSO's; what is under test is the sequencing and the rollback.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

const OLD_SSO_SETTINGS = [
    'sso' => ['pk' => 'old-app', 'slug' => 'old-slug', 'provider_obj' => ['pk' => 7]],
    'sso_client_id' => 'encrypted-old-id',
    'sso_client_secret' => 'encrypted-old-secret',
];

beforeEach(function () {
    // The demo app signs people in through an SSO server, unless a test says it does not
    DemoAppProfile::$sso = true;
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    DemoAppProfile::$sso = false;
    $this->restoreServerInterfaces();
});

function ssoServer(string $name): Server
{
    return Server::factory()->create([
        'name' => $name,
        'type' => 'sso',
        'interface' => 'authentik',
        'host' => "{$name}.example.com",
        'default_web_server' => 0,
    ]);
}

/**
 * An active app that signs in through an SSO server called "old-sso", with the settings that
 * go with it.
 *
 * @return array{AppInstance, OrgServer}
 */
function appOnSsoServer(): array
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);
    $instance = test()->runActivate($plan, $org, $app);

    $old = OrgServer::forceCreate(['organization_id' => $org->id, 'server_id' => ssoServer('old-sso')->id]);

    $instance->sso_server_id = $old->id;
    $instance->settings = array_merge($instance->settings ?? [], OLD_SSO_SETTINGS);
    $instance->save();

    // What setting it up for the first time did is not what is under test
    FakeSSO::reset();

    return [$instance->fresh(), $old];
}

function startSsoMove(AppInstance $instance, Server $target): Task
{
    return Action::execute(new ApplicationSsoMove($instance, $target))->fresh();
}

/**
 * Runs what the task is waiting on, then the task, until it is done or has failed. Tasks
 * started in the background are run by the runner when it polls them.
 */
function finishSsoMove(Task $task): Task
{
    for ($round = 0; $round < 10; $round++) {
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

it('sets the app up on the new server from nothing and points the app at it', function () {
    [$instance, $old] = appOnSsoServer();
    $target = ssoServer('new-sso');

    $task = finishSsoMove(startSsoMove($instance, $target));

    $new = OrgServer::where('organization_id', $instance->organization_id)->where('server_id', $target->id)->first();
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($new)->not->toBeNull()
        ->and($instance->fresh()->sso_server_id)->toBe($new->id)
        ->and($instance->fresh()->status)->toBe('active');

    // The first thing the new server is asked is to add the app, with none of the old server's
    // ids to look for, and the old server is never asked anything
    expect(FakeSSO::$calls[0])->toBe(['add', $new->id, null])
        ->and(collect(FakeSSO::$calls)->pluck(1)->unique()->all())->toBe([$new->id]);

    // The old settings are kept on the task, for removing the old provider later
    expect($task->getValue('sso_snapshot'))->toBe(OLD_SSO_SETTINGS)
        ->and($task->getValue('previous_org_server_id'))->toBe($old->id);
});

it('runs the standard update only after the new server is set up', function () {
    [$instance] = appOnSsoServer();

    $task = startSsoMove($instance, ssoServer('new-sso'));

    // Waiting for the setup: nothing is updated yet
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS)
        ->and(Task::where('action_slug', 'application_sso_setup')->where('task_group', $task->task_group)->count())->toBe(1)
        ->and(Task::where('action_slug', 'application_update')->where('task_group', $task->task_group)->count())->toBe(0);
});

it('puts the old server and settings back when the new server cannot be set up', function () {
    [$instance, $old] = appOnSsoServer();
    FakeSSO::$failure = 'authentik unreachable';

    $task = finishSsoMove(startSsoMove($instance, ssoServer('new-sso')));

    expect($task->status)->toBe(TaskStatus::FAILED)
        ->and($task->error_message)->toContain('authentik unreachable')->toContain('old-sso');

    $instance = $instance->fresh();
    expect($instance->sso_server_id)->toBe($old->id)
        ->and(collect($instance->settings)->only(array_keys(OLD_SSO_SETTINGS))->all())->toBe(OLD_SSO_SETTINGS);

    // A standard update puts the app back on the old server
    $rollback_update = Task::where('action_slug', 'application_update')->where('id', '>', $task->id)->sole();
    expect($instance->status)->toBe('updating');

    app(TaskRunner::class)->poll($rollback_update);
    expect($instance->fresh()->status)->toBe('active');
});

it('rolls back when the app does not come back up in time', function () {
    [$instance, $old] = appOnSsoServer();

    $task = startSsoMove($instance, ssoServer('new-sso'));

    // On to the update step, which is then left running
    for ($round = 0; $round < 5 && ! TaskStep::where('task_id', $task->id)->where('name', 'apply_update')->exists(); $round++) {
        Task::where('task_group', $task->task_group)->where('id', '!=', $task->id)->whereIn('status', TaskStatus::openValues())->get()
            ->each(fn (Task $other) => app(TaskRunner::class)->tryPoll($other));
        app(TaskRunner::class)->poll($task);
    }

    TaskStep::where('task_id', $task->id)->where('name', 'apply_update')->update([
        'started_at' => now()->subMinutes(ApplicationSsoMove::UPDATE_TIMEOUT_MINUTES + 1),
    ]);
    app(TaskRunner::class)->poll($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED)
        ->and($instance->fresh()->sso_server_id)->toBe($old->id)
        ->and(collect($instance->fresh()->settings)->only(array_keys(OLD_SSO_SETTINGS))->all())->toBe(OLD_SSO_SETTINGS);
});

it('refuses a server that cannot take the app, before touching anything', function () {
    [$instance, $old] = appOnSsoServer();
    $database = Server::factory()->create(['type' => 'database', 'name' => 'a-db', 'host' => 'a-db.example.com']);

    $task = startSsoMove($instance, $database);

    expect($task->status)->toBe(TaskStatus::FAILED)
        ->and($task->error_message)->toContain(__('messages.server_move.wrong_type', ['type' => 'sso']));

    $instance = $instance->fresh();
    expect($instance->sso_server_id)->toBe($old->id)
        ->and(collect($instance->settings)->only(array_keys(OLD_SSO_SETTINGS))->all())->toBe(OLD_SSO_SETTINGS)
        ->and($instance->status)->toBe('active')
        ->and(FakeSSO::$calls)->toBe([]);
});

it('refuses an app that does not support single sign-on, before touching anything', function () {
    [$instance, $old] = appOnSsoServer();
    DemoAppProfile::$sso = false;

    expect(ApplicationSsoMove::blockers($instance, ssoServer('new-sso')))
        ->toBe([__('messages.server_move.no_sso_support', ['type' => 'sso'])]);

    $task = startSsoMove($instance, ssoServer('other-sso'));

    expect($task->status)->toBe(TaskStatus::FAILED)
        ->and($instance->fresh()->sso_server_id)->toBe($old->id)
        ->and(collect($instance->fresh()->settings)->only(array_keys(OLD_SSO_SETTINGS))->all())->toBe(OLD_SSO_SETTINGS)
        ->and(FakeSSO::$calls)->toBe([]);
});

it('lists everything in the way of the move at once', function () {
    [$instance, $old] = appOnSsoServer();
    $instance->status = 'updating';

    expect(ApplicationSsoMove::blockers($instance, $old->server))->toBe([
        __('messages.server_move.app_not_active', ['type' => 'sso']),
        __('messages.server_move.same_server', ['type' => 'sso']),
    ])->and(ApplicationSsoMove::blockers($instance, ssoServer('new-sso')))->toBe([
        __('messages.server_move.app_not_active', ['type' => 'sso']),
    ])->and(ApplicationSsoMove::blockers($instance, ssoServer('other-sso'), require_active: false))->toBe([]);
});
