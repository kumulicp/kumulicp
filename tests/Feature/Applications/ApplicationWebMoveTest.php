<?php

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationWebCleanup;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Apps\WebMove\AwaitVolume;
use App\Actions\Apps\WebMove\RunSettingsJob;
use App\Actions\Apps\WebMove\Verify;
use App\Actions\Steps\AwaitConfirmation;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Integrations\Applications\Nextcloud\NextcloudProfile;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use App\OrgSubdomain;
use App\Server;
use App\Services\BackupService;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Support\Facades\Backup;
use App\Support\ServerReach;
use App\Task;
use App\TaskStep;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\ServerManagers\FakeServerManager;
use Tests\Support\SSO\FakeSSO;
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
    DemoAppProfile::$sso = false;
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

function startWebMove(AppInstance $instance, Server $target, array $storage = ['volume' => 'new']): Task
{
    return Action::execute(new ApplicationWebMove($instance, $target, $storage))->fresh();
}

/**
 * Runs what the task is waiting on, then the task, a few times over: as far as it gets, which is the
 * end, or the step that waits for an administrator.
 */
/**
 * What a queue worker does between jobs, for what is memoized per job: the app instances the Application
 * service has already looked up (it would not see a setting that was changed since). The rest, such as the
 * apps and server interfaces the test registered, stays.
 */
function startNextJob(): void
{
    (function () {
        $this->instances = [];
    })->call(app('applications'));
}

function advanceWebMove(Task $task, int $rounds = 8, ?Closure $until = null): Task
{
    for ($round = 0; $round < $rounds; $round++) {
        if (in_array($task->fresh()->status, [TaskStatus::COMPLETE, TaskStatus::FAILED]) || ($until && $until($task->fresh()))) {
            break;
        }

        Task::where('task_group', $task->task_group)
            ->where('id', '!=', $task->id)
            ->whereIn('status', TaskStatus::openValues())
            ->get()
            ->each(function (Task $other) {
                // Each is a job of its own: with the memoized app instances of the last one, a setting that was
                // changed since would not be seen
                startNextJob();
                app(TaskRunner::class)->tryPoll($other);
            });

        startNextJob();
        app(TaskRunner::class)->poll($task);
    }

    return $task->fresh();
}

it('stops the app, waits for its volume, installs it on the new server against it and runs it there', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');

    $task = advanceWebMove(startWebMove($instance, $target, ['volume' => 'existing', 'claim' => 'restored-data', 'access_mode' => 'ReadWriteMany']));

    // Stopped where it is, and waiting for the volume to be restored on the new cluster
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS)
        ->and($instance->fresh()->setting('quiesced_for_move'))->toBeTrue()
        ->and($instance->fresh()->web_server_id)->toBe($old->id)
        ->and(AwaitConfirmation::waiting($instance)?->id)->toBe($task->id)
        ->and(Task::where('action_slug', 'application_update')->where('task_group', $task->task_group)->first()->getValue('steps'))->toBe(['release']);

    AwaitVolume::confirm($task);
    $task = advanceWebMove($task);

    $new = OrgServer::where('organization_id', $instance->organization_id)->where('server_id', $target->id)->first();
    $instance = $instance->fresh();
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($instance->web_server_id)->toBe($new->id)
        ->and($instance->status)->toBe('active')
        // Told to stop no longer, and given the volume
        ->and($instance->setting('quiesced_for_move'))->toBeFalse()
        ->and($instance->setting('existing_claim'))->toBe('restored-data')
        ->and($instance->setting('configurations.persistence-accessMode'))->toBe('ReadWriteMany')
        // Asked of the new server, not the old one, whether it is running
        ->and(collect(FakeServerManager::$calls)->contains(['checkStatus', $new->id, $instance->id]))->toBeTrue()
        ->and(collect(FakeServerManager::$calls)->contains(['checkStatus', $old->id, $instance->id]))->toBeFalse();
});


it('puts the old settings and server back, and scales the old release up, when the install fails', function () {
    [$instance, $old] = appOnWebServer();
    $instance->settings = array_merge($instance->settings ?? [], ['existing_claim' => 'old-claim']);
    $instance->save();

    $task = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2'), ['volume' => 'existing', 'claim' => 'restored-data']));
    AwaitVolume::confirm($task);

    // Past the wait, into the install
    app(TaskRunner::class)->poll($task);
    $install = Task::find($task->fresh()->getValue('update_task_id'));
    expect($instance->fresh()->setting('existing_claim'))->toBe('restored-data');

    $install->status = TaskStatus::FAILED;
    $install->error_message = 'helm install failed';
    $install->save();
    app(TaskRunner::class)->poll($task);

    $instance = $instance->fresh();
    expect($task->fresh()->status)->toBe(TaskStatus::FAILED)
        ->and($task->fresh()->error_message)->toContain('helm install failed')->toContain('old-cluster')
        ->and($instance->web_server_id)->toBe($old->id)
        ->and($instance->setting('existing_claim'))->toBe('old-claim')
        ->and($instance->setting('quiesced_for_move'))->toBeFalse();

    // Another update, which scales the old release up
    $rollback = Task::where('action_slug', 'application_update')->where('id', '>', $install->id)->sole();
    expect($instance->status)->toBe('updating');

    app(TaskRunner::class)->poll($rollback);
    expect($instance->fresh()->status)->toBe('active');
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

it('fails the move, saying so, when the release is found stopped once it is installed', function () {
    [$instance] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');

    $task = advanceWebMove(
        Action::execute(new ApplicationWebMove($instance, $target, ['volume' => 'new']))->fresh(),
        until: fn (Task $move) => (bool) $move->getValue('update_task_id'),
    );

    FakeServerManager::markInactive($instance->id);
    $result = (new Verify)->run($task->fresh());

    expect($result->outcome)->toBe(ActionResult::FAIL)
        ->and($result->reason)->toContain('stopped');
});

it('refreshes the copies of the old address in the app\'s subdomains, and only those', function () {
    [$instance] = appOnWebServer();
    $subdomain = fn (string $name, string $value) => OrgSubdomain::forceCreate([
        'organization_id' => $instance->organization_id,
        'app_instance_id' => $instance->id,
        'host' => $name,
        'name' => "{$name}.example.com",
        'type' => 'app',
        'value' => $value,
        'status' => 'active',
    ]);
    $copy = $subdomain('app', '10.0.0.1');
    $other = $subdomain('other', 'somewhere.example.net');

    $task = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2')));

    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($copy->fresh()->value)->toBe('10.0.0.2')
        ->and($other->fresh()->value)->toBe('somewhere.example.net');
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

it('removes the old release by asking the old server to uninstall it, and nothing else', function () {
    [$instance, $old] = appOnWebServer();

    $move = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2')));
    $instance = $instance->fresh();
    FakeServerManager::$calls = [];

    expect($move->status)->toBe(TaskStatus::COMPLETE)
        ->and(ApplicationWebCleanup::pending($instance)->pluck('id')->all())->toBe([$move->id]);

    $cleanup = advanceWebMove(Action::execute(new ApplicationWebCleanup($instance, $move))->fresh());

    // Asked of the old server alone, and not to delete the app, which would also rewrite its redirects there
    expect($cleanup->status)->toBe(TaskStatus::COMPLETE)
        ->and(FakeServerManager::$calls)->toBe([['uninstallRelease', $old->id, $instance->id]])
        ->and($move->fresh()->getValue('old_release_removed_at'))->not->toBeNull()
        ->and(ApplicationWebCleanup::pending($instance->fresh())->all())->toBe([]);
});

it('does not remove the release of the server the app runs on', function () {
    [$instance, $old] = appOnWebServer();

    $move = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2')));

    // Moved back onto the server it came from: that release is the app's now
    $instance = $instance->fresh();
    $instance->web_server_id = $old->id;
    $instance->save();
    FakeServerManager::$calls = [];

    expect(ApplicationWebCleanup::pending($instance->fresh())->all())->toBe([]);

    $cleanup = advanceWebMove(Action::execute(new ApplicationWebCleanup($instance->fresh(), $move))->fresh());

    expect($cleanup->status)->toBe(TaskStatus::FAILED)
        ->and(FakeServerManager::$calls)->toBe([]);
});

it('only moves to an app server that can reach the app\'s database, which is moved first, by way of one both can reach', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');

    // The database is on a network only the old app server is on
    $database = Server::factory()->create(['type' => 'database', 'name' => 'inner-db', 'host' => 'inner-db.example.com']);
    $database->app_servers()->sync([$old->server_id]);
    $instance->database_server_id = OrgServer::forceCreate(['organization_id' => $instance->organization_id, 'server_id' => $database->id])->id;
    $instance->save();
    $instance = $instance->fresh();

    expect(ApplicationWebMove::blockers($instance, $target))->toBe([
        __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => 'inner-db']),
    ]);

    // Moved to a database both app servers can reach, which can be done from where the app is now
    $bridge = Server::factory()->create(['type' => 'database', 'name' => 'bridge-db', 'host' => 'bridge-db.example.com']);
    $bridge->app_servers()->sync([$old->server_id, $target->id]);
    $instance->database_server_id = OrgServer::forceCreate(['organization_id' => $instance->organization_id, 'server_id' => $bridge->id])->id;
    $instance->save();

    expect(ApplicationWebMove::blockers($instance->fresh(), $target))->toBe([])
        ->and(ServerReach::reachableFromAll('database', [$old->server, $target])->pluck('name')->all())->toBe(['bridge-db']);
});

it('waits for the volume that was chosen when it was started, without asking for it again', function () {
    [$instance] = appOnWebServer();

    $task = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2'), [
        'volume' => 'existing',
        'claim' => 'restored-data',
        'access_mode' => 'ReadWriteMany',
    ]));

    // Waiting for the volume to be restored, which is all that is asked: it already says what to
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS)
        ->and($task->error_message)->toContain('restored-data');

    AwaitVolume::confirm($task);
    $task = advanceWebMove($task);

    $instance = $instance->fresh();
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($instance->setting('existing_claim'))->toBe('restored-data')
        ->and($instance->setting('configurations.persistence-accessMode'))->toBe('ReadWriteMany');
});

it('does not wait at all for an app that is to start with a new volume', function () {
    [$instance] = appOnWebServer();
    $instance->settings = array_merge($instance->settings ?? [], ['existing_claim' => 'old-claim']);
    $instance->save();

    $task = advanceWebMove(startWebMove($instance, webServer('new-cluster', '10.0.0.2'), ['volume' => 'new']));

    // Nothing to restore: it goes straight on, and the old volume's claim is not looked for there
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and(AwaitConfirmation::waiting($instance))->toBeNull()
        ->and($instance->fresh()->setting('existing_claim'))->toBe('');
});

it('keeps the volume that was chosen when it is started over', function () {
    [$instance] = appOnWebServer();
    $task = startWebMove($instance, webServer('new-cluster', '10.0.0.2'), ['volume' => 'existing', 'claim' => 'restored-data']);
    $task->custom_values = array_merge($task->customValues(), ['quiesce_update_task_id' => 1, 'update_task_id' => 2]);

    ApplicationWebMove::reset($task);

    expect(array_keys($task->customValues()))->toBe(['target_server_id', 'storage'])
        ->and($task->getValue('storage.claim'))->toBe('restored-data');
});

/**
 * A database server of the built-in kind (which can copy to and from named servers), reached from the
 * given app servers.
 *
 * @param  array<int, int>  $reachable_from  ids of the web servers
 */
function webMoveDatabaseServer(string $name, array $reachable_from): Server
{
    $server = Server::factory()->create([
        'name' => $name,
        'type' => 'database',
        'interface' => 'app_database',
        'host' => "{$name}.db.internal",
        'internal_address' => "{$name}.db.svc",
        'default_web_server' => 0,
    ]);
    $server->app_servers()->sync($reachable_from);

    return $server;
}

function giveAppDatabaseOn(AppInstance $instance, Server $database): OrgServer
{
    $org_server = OrgServer::forceCreate(['organization_id' => $instance->organization_id, 'server_id' => $database->id]);
    $org_server->backup_server_id = $org_server->id;
    $org_server->save();

    $instance->database_server_id = $org_server->id;
    $instance->databasename = 'org_demo';
    $instance->save();

    return $org_server;
}

/**
 * Stands in for the backup driver: records what it is asked to do, and can be told to fail the restore.
 */
function fakeWebMoveBackupDriver(?string $restore_failure = null): ArrayObject
{
    $log = new ArrayObject;

    Backup::shouldReceive('supportsServerTargets')->andReturnUsing(fn (Server $server) => (new BackupService)->supportsServerTargets($server));
    Backup::shouldReceive('connect')->andReturnUsing(function (OrgBackup $backup) use ($log, $restore_failure) {
        return new class($backup, $log, $restore_failure)
        {
            public function __construct(private OrgBackup $backup, private ArrayObject $log, private ?string $restore_failure) {}

            public function run()
            {
                $this->log[] = 'backup';
                $this->backup->backup_name = 'dump-file';
                $this->backup->save();

                return ['job_id' => '', 'status' => 'completed'];
            }

            public function restore()
            {
                $this->log[] = 'restore';

                if ($this->restore_failure) {
                    throw new Exception($this->restore_failure);
                }

                return ['job_id' => '', 'status' => 'completed'];
            }
        };
    });

    return $log;
}

function updatesOf(OrgServer $server): int
{
    return collect(FakeServerManager::$calls)->filter(fn (array $call) => $call[0] === 'update' && $call[1] === $server->id)->count();
}

it('moves the database along with the app to one only the new app server can reach, and leaves the old release as it was once stopped', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $old_db = webMoveDatabaseServer('old-db', [$old->server_id]);
    $new_db = webMoveDatabaseServer('new-db', [$target->id]);
    $previous_db = giveAppDatabaseOn($instance, $old_db);
    $log = fakeWebMoveBackupDriver();
    $instance = $instance->fresh();

    // The database the app has is out of reach of the new app server, and the one it could have is out of
    // reach of the old one: that is not needed, as the old release is not updated to use it
    expect(ApplicationWebMove::blockers($instance, $target))->toBe([
        __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => 'old-db']),
    ])->and(ApplicationWebMove::blockers($instance, $target, database_server: $new_db))->toBe([]);

    $task = advanceWebMove(Action::execute(new ApplicationWebMove($instance, $target, ['volume' => 'new'], $new_db))->fresh());

    $new = OrgServer::where('organization_id', $instance->organization_id)->where('server_id', $target->id)->first();
    $instance = $instance->fresh();
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($log->getArrayCopy())->toBe(['backup', 'restore'])
        ->and($instance->web_server_id)->toBe($new->id)
        ->and($instance->database_server->server->name)->toBe('new-db')
        ->and($instance->status)->toBe('active')
        // Taken from the database the app had, and restored to the new one
        ->and(OrgBackup::where('app_instance_id', $instance->id)->where('action', 'backup')->sole()->org_server_id)->toBe($previous_db->id)
        // Installed on the new server once, and the old server was not updated after it was stopped
        ->and(updatesOf($new))->toBe(1);
});

it('does not copy a database or touch the sign-in when the move was not started with a new one', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $old_db = webMoveDatabaseServer('old-db', [$old->server_id, $target->id]);
    $previous_db = giveAppDatabaseOn($instance, $old_db);
    $log = fakeWebMoveBackupDriver();

    $task = advanceWebMove(Action::execute(new ApplicationWebMove($instance->fresh(), $target, ['volume' => 'new']))->fresh());

    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($log->getArrayCopy())->toBe([])
        ->and($instance->fresh()->database_server_id)->toBe($previous_db->id)
        ->and(OrgBackup::where('app_instance_id', $instance->id)->count())->toBe(0);
});

it('refuses a database server for the move that the new app server can\'t reach, or that the app can\'t be copied to', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $previous_db = giveAppDatabaseOn($instance, webMoveDatabaseServer('old-db', [$old->server_id]));
    $elsewhere = webMoveDatabaseServer('elsewhere-db', [$old->server_id]);
    fakeWebMoveBackupDriver();
    $instance = $instance->fresh();

    expect(ApplicationWebMove::blockers($instance, $target, database_server: $elsewhere))->toContain(
        __('messages.server_move.cannot_reach_database', ['type' => 'web', 'server' => 'elsewhere-db']),
    )
        // The one it has is not somewhere to move it to
        ->and(ApplicationWebMove::blockers($instance, $target, database_server: $previous_db->server))->toContain(__('messages.database_migration.same_server'));
});

it('puts the old database and server back, and scales the old release up, when the install fails', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $previous_db = giveAppDatabaseOn($instance, webMoveDatabaseServer('old-db', [$old->server_id]));
    $new_db = webMoveDatabaseServer('new-db', [$target->id]);
    fakeWebMoveBackupDriver();

    $task = advanceWebMove(Action::execute(new ApplicationWebMove($instance->fresh(), $target, ['volume' => 'existing', 'claim' => 'restored-data'], $new_db))->fresh());
    AwaitVolume::confirm($task);

    // Past the wait, into the install, which is against the new database
    app(TaskRunner::class)->poll($task);
    expect($instance->fresh()->database_server->server->name)->toBe('new-db');
    $install = Task::find($task->fresh()->getValue('update_task_id'));
    $install->status = TaskStatus::FAILED;
    $install->error_message = 'helm install failed';
    $install->save();
    app(TaskRunner::class)->poll($task);

    $instance = $instance->fresh();
    expect($task->fresh()->status)->toBe(TaskStatus::FAILED)
        ->and($instance->web_server_id)->toBe($old->id)
        ->and($instance->database_server_id)->toBe($previous_db->id)
        ->and($instance->setting('quiesced_for_move'))->toBeFalse();

    // Another update, which scales the old release up: for the old database, as it has been given back
    $rollback = Task::where('action_slug', 'application_update')->where('id', '>', $install->id)->sole();
    app(TaskRunner::class)->poll($rollback);
    expect($instance->fresh()->status)->toBe('active');
});

it('leaves the app where it was, with its database, when the database can\'t be copied', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $previous_db = giveAppDatabaseOn($instance, webMoveDatabaseServer('old-db', [$old->server_id]));
    $new_db = webMoveDatabaseServer('new-db', [$target->id]);
    fakeWebMoveBackupDriver('the new server refused it');

    $task = advanceWebMove(Action::execute(new ApplicationWebMove($instance->fresh(), $target, ['volume' => 'new'], $new_db))->fresh());

    $instance = $instance->fresh();
    expect($task->status)->toBe(TaskStatus::FAILED)
        ->and($instance->web_server_id)->toBe($old->id)
        ->and($instance->database_server_id)->toBe($previous_db->id)
        ->and($instance->setting('quiesced_for_move'))->toBeFalse();
});

const WEB_MOVE_OLD_SSO_SETTINGS = [
    'sso' => ['pk' => 'old-app', 'slug' => 'old-slug', 'provider_obj' => ['pk' => 7]],
    'sso_client_id' => 'encrypted-old-id',
    'sso_client_secret' => 'encrypted-old-secret',
];

/**
 * Gives the app a sign-in server (called "old-sso") that only the old app server can reach, with the
 * settings that go with it.
 */
function giveAppSignInOn(AppInstance $instance, OrgServer $old_web): OrgServer
{
    DemoAppProfile::$sso = true;

    $server = Server::factory()->create(['name' => 'old-sso', 'type' => 'sso', 'interface' => 'authentik', 'host' => 'old-sso.example.com', 'default_web_server' => 0]);
    $server->app_servers()->sync([$old_web->server_id]);

    $org_server = OrgServer::forceCreate(['organization_id' => $instance->organization_id, 'server_id' => $server->id]);
    $instance->sso_server_id = $org_server->id;
    $instance->settings = array_merge($instance->settings ?? [], WEB_MOVE_OLD_SSO_SETTINGS);
    $instance->save();

    // What setting it up for the first time did is not what is under test
    FakeSSO::reset();

    return $org_server;
}

function newSignInServer(Server $reachable_from): Server
{
    $server = Server::factory()->create(['name' => 'new-sso', 'type' => 'sso', 'interface' => 'authentik', 'host' => 'new-sso.example.com', 'default_web_server' => 0]);
    $server->app_servers()->sync([$reachable_from->id]);

    return $server;
}

it('sets the sign-in up on a new server along with the app, and never asks the old server anything', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $previous_sso = giveAppSignInOn($instance, $old);
    $new_sso = newSignInServer($target);
    $instance = $instance->fresh();

    // The sign-in the app has can't be reached from the new app server; one it can reach, can
    expect(ApplicationWebMove::blockers($instance, $target))->toBe([
        __('messages.server_move.cannot_reach_sso', ['type' => 'web', 'server' => 'old-sso']),
    ])->and(ApplicationWebMove::blockers($instance, $target, sso_server: $new_sso))->toBe([]);

    $task = advanceWebMove(Action::execute(new ApplicationWebMove($instance, $target, ['volume' => 'new'], null, $new_sso))->fresh());

    $new = OrgServer::where('organization_id', $instance->organization_id)->where('server_id', $new_sso->id)->first();
    $instance = $instance->fresh();
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and($instance->sso_server_id)->toBe($new->id)
        ->and($instance->status)->toBe('active')
        // Set up from nothing, on the new server only, and nothing of the old server's is removed (the stop
        // is a standard update, which gives the old server's entry what it had)
        ->and(collect(FakeSSO::$calls)->where(0, 'add')->values()->all())->toBe([['add', $new->id, null]])
        ->and(collect(FakeSSO::$calls)->where(0, 'delete')->count())->toBe(0)
        // The old settings are kept on the task, for removing the old provider later
        ->and($task->getValue('sso_snapshot'))->toBe(WEB_MOVE_OLD_SSO_SETTINGS)
        ->and($task->getValue('sso_previous_org_server_id'))->toBe($previous_sso->id)
        ->and($instance->web_server->server_id)->toBe($target->id)
        ->and(updatesOf($instance->web_server))->toBe(1);
});

it('puts the old sign-in server and settings back when the new one can\'t be set up', function () {
    [$instance, $old] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');
    $previous_sso = giveAppSignInOn($instance, $old);
    $new_sso = newSignInServer($target);

    // As far as the sign-in being set up, which then fails
    $task = advanceWebMove(
        Action::execute(new ApplicationWebMove($instance->fresh(), $target, ['volume' => 'new'], null, $new_sso))->fresh(),
        until: fn (Task $move) => (bool) $move->getValue('sso_setup_task_id'),
    );
    expect($instance->fresh()->sso_server->server->name)->toBe('new-sso');

    $setup = Task::find($task->getValue('sso_setup_task_id'));
    $setup->status = TaskStatus::FAILED;
    $setup->error_message = 'authentik unreachable';
    $setup->save();
    app(TaskRunner::class)->poll($task);

    $instance = $instance->fresh();
    expect($task->fresh()->status)->toBe(TaskStatus::FAILED)
        ->and($task->fresh()->error_message)->toContain('authentik unreachable')
        ->and($instance->web_server_id)->toBe($old->id)
        ->and($instance->sso_server_id)->toBe($previous_sso->id)
        ->and(collect($instance->settings)->only(array_keys(WEB_MOVE_OLD_SSO_SETTINGS))->all())->toBe(WEB_MOVE_OLD_SSO_SETTINGS)
        ->and($instance->setting('quiesced_for_move'))->toBeFalse();
});

function moveStepStatus(Task $move, string $step): string
{
    $status = TaskStep::where('task_id', $move->id)->where('name', $step)->value('status');

    return $status instanceof BackedEnum ? $status->value : (string) $status;
}

it('does not run the settings job until the release is running on the new server', function () {
    [$instance] = appOnWebServer();
    $target = webServer('new-cluster', '10.0.0.2');

    // As far as the install being started
    $task = advanceWebMove(
        Action::execute(new ApplicationWebMove($instance, $target, ['volume' => 'new']))->fresh(),
        until: fn (Task $move) => (bool) $move->getValue('update_task_id'),
    );
    expect($task->getValue('update_task_id'))->not->toBeNull();

    // The release is not running (yet): the move waits for it, and the job, which runs the app's own commands
    // against it, is not made
    FakeServerManager::markInactive($instance->id);
    $task = advanceWebMove($task, 5);

    expect($task->status)->toBe(TaskStatus::IN_PROGRESS)
        ->and(Task::find($task->getValue('update_task_id'))->status)->not->toBe(TaskStatus::COMPLETE)
        ->and(moveStepStatus($task, 'run_settings_job'))->toBe('pending')
        ->and(Task::where('action_slug', 'application_update_job')->count())->toBe(0);

    // Once it runs, the move goes on: Verify, then the job, then the DNS records
    FakeServerManager::clearInactive($instance->id);
    $task = advanceWebMove($task);

    $steps = TaskStep::where('task_id', $task->id)->orderBy('id')->pluck('name')->all();
    expect($task->status)->toBe(TaskStatus::COMPLETE)
        ->and(array_search('verify', $steps))->toBeLessThan(array_search('run_settings_job', $steps))
        ->and(array_search('run_settings_job', $steps))->toBeLessThan(array_search('refresh_dns', $steps));
});

describe('the settings job step', function () {
    function settingsJobMove(?TaskStatus $job_status, string $error = ''): array
    {
        [$instance] = appOnWebServer();
        $move = Action::execute(new ApplicationWebMove($instance, webServer('new-cluster', '10.0.0.2'), ['volume' => 'new']))->fresh();

        if ($job_status) {
            $job = $move->replicate(['dedup_key', 'dedup_open']);
            $job->action_slug = 'application_update_job';
            $job->status = $job_status;
            $job->error_message = $error;
            $job->save();
            $move->custom_values = array_merge($move->customValues(), ['settings_job_task_id' => $job->id]);
            $move->save();
        } else {
            $move->custom_values = array_merge($move->customValues(), ['settings_job_task_id' => 999999]);
            $move->save();
        }

        return [$move->fresh(), new RunSettingsJob];
    }

    it('is done when the job is', function () {
        [$move, $step] = settingsJobMove(TaskStatus::COMPLETE);

        expect($step->check($move)->outcome)->toBe(ActionResult::DONE);
    });

    it('is done when there is no such job for the app, which removes it as done', function () {
        [$move, $step] = settingsJobMove(null);

        expect($step->check($move)->outcome)->toBe(ActionResult::DONE);
    });

    it('waits while the job runs', function () {
        [$move, $step] = settingsJobMove(TaskStatus::IN_PROGRESS);

        expect($step->check($move)->outcome)->toBe(ActionResult::WAIT);
    });

    it('fails, saying why, when the job does', function () {
        [$move, $step] = settingsJobMove(TaskStatus::FAILED, 'occ config:system:set failed');

        $result = $step->check($move);

        expect($result->outcome)->toBe(ActionResult::FAIL)
            ->and($result->reason)->toBe('occ config:system:set failed');
    });
});
