<?php

use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\AppInstance;
use App\Contracts\BackupContract;
use App\Contracts\BackupServerTargetContract;
use App\Enums\TaskStatus;
use App\Organization;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
use App\Services\BackupService;
use App\Support\Facades\Action;
use App\Support\Facades\Backup;
use App\Task;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * Moving an app's database to another database server: fresh backup, restore
 * into the new server, point the app at it, standard app update -- and back
 * to the old server if any of that fails. The actual mysqldump / mysql calls
 * are BackupInterface's; here the Backup facade stands in for them so what's
 * under test is the sequencing and the rollback.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function databaseServer(string $name): Server
{
    return Server::factory()->create([
        'name' => $name,
        'type' => 'database',
        'interface' => 'app_database',
        'host' => "{$name}.db.internal",
        'internal_address' => "{$name}.db.svc",
        'default_web_server' => 0,
    ]);
}

/**
 * An active app whose database lives on a database server called "old-db".
 *
 * @return array{AppInstance, OrgServer}
 */
function appOnDatabaseServer(): array
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();
    $org = Organization::find(1);
    $instance = test()->runActivate($plan, $org, $app);

    $old = OrgServer::create(['organization_id' => $org->id, 'server_id' => databaseServer('old-db')->id]);
    $old->backup_server_id = $old->id;
    $old->save();

    $instance->database_server_id = $old->id;
    $instance->databasename = 'org_demo';
    $instance->save();

    return [$instance->fresh(), $old];
}

/**
 * Stands in for BackupInterface: records what it's asked to do (returned log),
 * names the backup file when dumping and can be told to fail the restore.
 */
function fakeBackupDriver(?string $restore_failure = null): ArrayObject
{
    $log = new ArrayObject;

    // Only connect() is faked; which servers qualify stays the real thing.
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

/**
 * Stands in for a driver that hands the work to another service (kmanager):
 * the call returns at once with the backup still running, and the service
 * completes or fails it later, which the tests do by updating the OrgBackup the
 * way the backup callback does.
 */
function fakeRemoteBackupDriver(): ArrayObject
{
    $log = new ArrayObject;

    Backup::shouldReceive('supportsServerTargets')->andReturnUsing(fn (Server $server) => (new BackupService)->supportsServerTargets($server));
    Backup::shouldReceive('connect')->andReturnUsing(function (OrgBackup $backup) use ($log) {
        return new class($backup, $log)
        {
            public function __construct(private OrgBackup $backup, private ArrayObject $log) {}

            public function run()
            {
                $this->log[] = 'backup';

                return ['job_id' => 'job-'.$this->backup->id, 'status' => 'running'];
            }

            public function restore()
            {
                $this->log[] = 'restore';

                return ['job_id' => 'job-'.$this->backup->id];
            }
        };
    });

    return $log;
}

/** What the callback from the remote service does to the backup record */
function remoteReports(string $action, string $status, ?string $backup_name = null): void
{
    $backup = OrgBackup::where('action', $action)->sole();
    $backup->status = $status;
    $backup->completed_at = $status === 'completed' ? now() : null;
    $backup->backup_name = $backup_name ?? $backup->backup_name;
    $backup->save();
}

function startMigration(AppInstance $instance, Server $target): Task
{
    // execute() runs the task on the sync queue in tests, including the
    // standard ApplicationUpdate it schedules for the cutover.
    return Action::execute(new ApplicationDatabaseMigrate($instance, $target))->fresh();
}

it('backs up, restores to the new server, switches the app and runs a standard update', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    $calls = fakeBackupDriver();

    $task = startMigration($instance, $target);

    expect($calls->getArrayCopy())->toBe(['backup', 'restore']);

    $new = OrgServer::where('organization_id', $instance->organization_id)->where('server_id', $target->id)->first();
    expect($new)->not->toBeNull();
    expect($instance->fresh()->database_server_id)->toBe($new->id);

    // The dump is taken from the app's own server and loaded into the new one
    $backup = OrgBackup::where('action', 'backup')->sole();
    expect($backup->settings)->toBe(['source_org_server_id' => $old->id, 'full_copy' => true]);
    expect($backup->status)->toBe('completed');
    expect($backup->delete_at)->not->toBeNull();

    $restore = OrgBackup::where('action', 'restore')->sole();
    expect($restore->settings)->toBe(['target_org_server_id' => $new->id]);
    expect($restore->backup_name)->toBe('dump-file');
    expect($restore->status)->toBe('completed');

    // The standard update, in the same task group, is what carries the new host into the chart
    $update = Task::find($task->getValue('update_task_id'));
    expect($update->action_slug)->toBe('application_update');
    expect($update->task_group)->toBe($task->task_group);
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS);

    // Completes once the update does
    Action::complete($update);

    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
    expect($instance->fresh()->status)->toBe('active');
    expect($instance->fresh()->database_server_id)->toBe($new->id);
});

it('leaves the app on its current server when the restore fails', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    fakeBackupDriver(restore_failure: 'mysql: connection refused');

    $task = startMigration($instance, $target);

    expect($instance->fresh()->database_server_id)->toBe($old->id);
    expect($instance->fresh()->status)->toBe('active');
    expect($task->status)->toBe(TaskStatus::FAILED);
    expect($task->error_message)->toContain('mysql: connection refused')->toContain('old-db');

    // Never got as far as the update
    expect(Task::where('action_slug', 'application_update')->count())->toBe(0);
});

it('rolls back to the old server when the app update fails', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    fakeBackupDriver();

    $task = startMigration($instance, $target);
    $update = Task::find($task->getValue('update_task_id'));
    $update->status = TaskStatus::FAILED;
    $update->error_message = 'helm upgrade failed';
    $update->save();

    Action::complete($task);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::FAILED);
    expect($task->error_message)->toContain('helm upgrade failed')->toContain('old-db');
    expect($instance->fresh()->database_server_id)->toBe($old->id);

    // A new standard update puts the chart back on the old database
    $rollback_update = Task::where('action_slug', 'application_update')->sole();
    expect($rollback_update->id)->not->toBe($update->id);
    expect($instance->fresh()->status)->toBe('updating');

    Action::complete($rollback_update);
    expect($instance->fresh()->status)->toBe('active');
});

it('rolls back when the app does not come back up in time', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    fakeBackupDriver();

    $task = startMigration($instance, $target);
    $task->custom_values = array_merge($task->customValues(), [
        'cutover_at' => now()->subMinutes(ApplicationDatabaseMigrate::UPDATE_TIMEOUT_MINUTES + 1)->toIso8601String(),
    ]);
    $task->save();

    Action::complete($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($instance->fresh()->database_server_id)->toBe($old->id);
});

it('keeps waiting while the update is still running', function () {
    [$instance] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    fakeBackupDriver();

    $task = startMigration($instance, $target);

    Action::complete($task);

    expect($task->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);
    expect($instance->fresh()->database_server_id)->toBe($task->getValue('target_org_server_id'));
});

it('waits for a remote backup and restore to finish before each next step', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    $calls = fakeRemoteBackupDriver();

    $task = startMigration($instance, $target);

    // Only the backup has been asked for, and it is still running
    expect($calls->getArrayCopy())->toBe(['backup']);
    expect($task->status)->toBe(TaskStatus::IN_PROGRESS);
    expect(OrgBackup::where('action', 'backup')->sole()->status)->toBe('running');
    expect(OrgBackup::where('action', 'backup')->sole()->job_id)->not->toBeEmpty();

    Action::complete($task);
    expect($calls->getArrayCopy())->toBe(['backup']);

    // The service reports the backup done: now the restore is started, from its snapshot
    remoteReports('backup', 'completed', 'snapshot-1');
    Action::complete($task);

    expect($calls->getArrayCopy())->toBe(['backup', 'restore']);
    $restore = OrgBackup::where('action', 'restore')->sole();
    expect($restore->backup_name)->toBe('snapshot-1');
    expect($restore->status)->toBe('running');

    // The app is not switched until the data is on the new server
    Action::complete($task);
    expect($instance->fresh()->database_server_id)->toBe($old->id);
    expect(Task::where('action_slug', 'application_update')->count())->toBe(0);

    remoteReports('restore', 'completed');
    Action::complete($task);

    $new = OrgServer::where('organization_id', $instance->organization_id)->where('server_id', $target->id)->first();
    expect($instance->fresh()->database_server_id)->toBe($new->id);
    $update = Task::find($task->fresh()->getValue('update_task_id'));
    expect($update->action_slug)->toBe('application_update');

    Action::complete($update);
    expect($task->fresh()->status)->toBe(TaskStatus::COMPLETE);
    expect($instance->fresh()->status)->toBe('active');
});

it('does not start the restore twice when checked again before the backup finishes', function () {
    [$instance] = appOnDatabaseServer();
    $calls = fakeRemoteBackupDriver();

    $task = startMigration($instance, databaseServer('new-db'));
    remoteReports('backup', 'completed', 'snapshot-1');

    Action::complete($task);
    Action::complete($task);
    Action::complete($task);

    expect($calls->getArrayCopy())->toBe(['backup', 'restore']);
    expect(OrgBackup::where('action', 'restore')->count())->toBe(1);
});

it('stays on the old server when the remote backup fails', function () {
    [$instance, $old] = appOnDatabaseServer();
    $calls = fakeRemoteBackupDriver();

    $task = startMigration($instance, databaseServer('new-db'));
    remoteReports('backup', 'failed');

    Action::complete($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($task->fresh()->error_message)->toContain('old-db');
    expect($instance->fresh()->database_server_id)->toBe($old->id);
    expect($instance->fresh()->status)->toBe('active');
    expect($calls->getArrayCopy())->toBe(['backup']);
});

it('stays on the old server when the remote restore fails', function () {
    [$instance, $old] = appOnDatabaseServer();
    fakeRemoteBackupDriver();

    $task = startMigration($instance, databaseServer('new-db'));
    remoteReports('backup', 'completed', 'snapshot-1');
    Action::complete($task);
    remoteReports('restore', 'failed');

    Action::complete($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($instance->fresh()->database_server_id)->toBe($old->id);
    expect($instance->fresh()->status)->toBe('active');
    expect(Task::where('action_slug', 'application_update')->count())->toBe(0);
});

it('gives up when the remote service never reports back', function () {
    [$instance, $old] = appOnDatabaseServer();
    fakeRemoteBackupDriver();

    $task = startMigration($instance, databaseServer('new-db'));
    $task->custom_values = array_merge($task->customValues(), [
        'stage_started_at' => now()->subMinutes(ApplicationDatabaseMigrate::COPY_TIMEOUT_MINUTES + 1)->toIso8601String(),
    ]);
    $task->save();

    Action::complete($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($instance->fresh()->database_server_id)->toBe($old->id);
    expect($instance->fresh()->status)->toBe('active');
});

it('fails when the finished backup has no file to restore', function () {
    [$instance, $old] = appOnDatabaseServer();
    $calls = fakeRemoteBackupDriver();

    $task = startMigration($instance, databaseServer('new-db'));
    remoteReports('backup', 'completed');

    Action::complete($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED);
    expect($instance->fresh()->database_server_id)->toBe($old->id);
    expect($calls->getArrayCopy())->toBe(['backup']);
});

it('only offers other database servers that can be backed up and restored', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');
    $no_driver = Server::factory()->create(['name' => 'odd-db', 'type' => 'database', 'interface' => 'something_else', 'host' => 'odd', 'internal_address' => 'odd']);

    expect(ApplicationDatabaseMigrate::targets($instance)->pluck('id')->values()->all())->toBe([$target->id]);
    expect(ApplicationDatabaseMigrate::blocker($instance, $old->server))->toBe(__('messages.database_migration.same_server'));
    expect(ApplicationDatabaseMigrate::blocker($instance, $no_driver))->toBe(__('messages.database_migration.invalid_server'));
    expect(ApplicationDatabaseMigrate::blocker($instance, Server::find(1)))->toBe(__('messages.database_migration.invalid_server'));
});

it('only allows drivers that declare they can work against a named server', function () {
    [$instance, $old] = appOnDatabaseServer();
    $target = databaseServer('new-db');

    // A remote driver (kmanager) that runs jobs asynchronously and has no
    // say over which server it talks to, and one that has opted in.
    $remote = new class implements BackupContract
    {
        public function exists() {}

        public function get() {}

        public function run() {}

        public function update() {}

        public function restore() {}

        public function delete() {}
    };
    $capable = new class implements BackupServerTargetContract
    {
        public function exists() {}

        public function get() {}

        public function run() {}

        public function update() {}

        public function restore() {}

        public function delete() {}
    };
    Backup::register('database', 'remote', $remote::class);
    Backup::register('database', 'capable', $capable::class);

    $remote_db = Server::factory()->create(['name' => 'remote-db', 'type' => 'database', 'interface' => 'remote', 'host' => 'remote', 'internal_address' => 'remote']);
    $capable_db = Server::factory()->create(['name' => 'capable-db', 'type' => 'database', 'interface' => 'capable', 'host' => 'capable', 'internal_address' => 'capable']);

    expect(Backup::driverExistsFor($remote_db))->toBeTrue();
    expect(Backup::supportsServerTargets($remote_db))->toBeFalse();
    expect(Backup::supportsServerTargets($capable_db))->toBeTrue();

    expect(ApplicationDatabaseMigrate::blocker($instance, $remote_db))->toBe(__('messages.database_migration.invalid_server'));
    expect(ApplicationDatabaseMigrate::blocker($instance, $capable_db))->toBeNull();
    expect(ApplicationDatabaseMigrate::targets($instance)->pluck('id')->sort()->values()->all())->toBe([$target->id, $capable_db->id]);
});

it('refuses a second server record for the host the app is already on', function () {
    [$instance, $old] = appOnDatabaseServer();
    $alias = databaseServer('alias-db');
    $alias->host = $old->server->host;
    $alias->save();

    expect(ApplicationDatabaseMigrate::blocker($instance, $alias))->toBe(__('messages.database_migration.same_server'));
    expect(ApplicationDatabaseMigrate::targets($instance)->pluck('id')->all())->toBe([]);
});

it('refuses to migrate an app that is not active or has no database', function () {
    [$instance] = appOnDatabaseServer();
    $target = databaseServer('new-db');

    $instance->status = 'updating';
    expect(ApplicationDatabaseMigrate::blocker($instance, $target))->toBe(__('messages.database_migration.not_active'));

    $instance->status = 'active';
    $instance->databasename = null;
    expect(ApplicationDatabaseMigrate::blocker($instance, $target))->toBe(__('messages.database_migration.no_database'));
});
