<?php

namespace App\Actions\Apps;

use App\Actions\Apps\DatabaseMigrate\BackupDatabase;
use App\Actions\Apps\DatabaseMigrate\CutOver;
use App\Actions\Apps\DatabaseMigrate\PrepareMigration;
use App\Actions\Apps\DatabaseMigrate\RestoreDatabase;
use App\Actions\Apps\ServerMove\ApplicationServerMove;
use App\Actions\Apps\ServerMove\MoveBlockers;
use App\AppInstance;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Backup;
use App\Task;

/**
 * Moves an app's database to another database server:
 *
 *  1. fresh backup of the app's database on its current server
 *  2. restore into the new server (which adds the database + user there)
 *  3. point the app at the new server
 *  4. standard app update, so the chart picks up the new database host
 *
 * The backup and restore may run on another service (kmanager) that reports
 * back when it is done, so each step is only started once the one before it is
 * confirmed complete: handle() starts the first, and poll(), which the task
 * runner calls every minute, moves on from there.
 *
 * Nothing on the old server is ever modified, so any failure before the app
 * comes back up on the new server is rolled back by pointing the app at the
 * old server again and running another standard update. The old database is
 * left in place afterwards for the same reason (and is the way back if the
 * new server turns out to be a mistake: migrate to the old server again).
 */
class ApplicationDatabaseMigrate extends ApplicationServerMove
{
    public $slug = 'application_database_migrate';

    public static bool $long_running = true;

    // How long the app has, after being pointed at the new database, to come
    // back up on it before the migration is rolled back.
    public const UPDATE_TIMEOUT_MINUTES = 20;

    // How long the backup, and then the restore, each have to finish before
    // the migration is given up on (the app is still on the old database then).
    public const COPY_TIMEOUT_MINUTES = 180;

    // How long the fresh backup is kept as a safety copy of the old data.
    public const BACKUP_RETENTION_DAYS = 14;

    public static function serverType(): string
    {
        return 'database';
    }

    protected static function messageGroup(): string
    {
        return 'database_migration';
    }

    protected function describe(AppInstance $app_instance, Server $target_server): string
    {
        return __('actions.migrating_app_database', [
            'app' => $app_instance->label,
            'server' => $target_server->name,
        ]);
    }

    /**
     * Everything that stops this app being migrated to $target_server (nothing if it can),
     * all at once so none has to be fixed to find the next.
     *
     * @return array<int, string>
     */
    public static function blockers(AppInstance $app_instance, Server $target_server, bool $require_active = true): array
    {
        $current = $app_instance->database_server;
        $messages = [];

        if (! $app_instance->databasename || ! $current) {
            $messages[] = __('messages.database_migration.no_database');
        }

        // What every move of a server checks, in the words the database migration has always used
        foreach ([
            ...MoveBlockers::app($app_instance, 'database', $require_active),
            ...MoveBlockers::target('database', $target_server),
        ] as $reason) {
            $messages[] = self::blockerMessage($reason);
        }

        // Both ends need a backup driver that can work against a named server:
        // the old server is dumped, the new one restored into.
        if (! $target_server->host
            || ! $target_server->internal_address
            || ! Backup::supportsServerTargets($target_server)
            || ($current && ! Backup::supportsServerTargets($current->server))) {
            $messages[] = __('messages.database_migration.invalid_server');
        }

        // Restoring replaces the database it loads into, so a different server
        // record for the same host is just as much the app's live database.
        foreach (MoveBlockers::same($app_instance, 'database', $target_server) as $reason) {
            $messages[] = self::blockerMessage($reason);
        }

        return array_values(array_unique($messages));
    }

    /**
     * The first thing in the way of migrating this app to $target_server, or null if
     * nothing is.
     */
    public static function blocker(AppInstance $app_instance, Server $target_server, bool $require_active = true): ?string
    {
        return self::blockers($app_instance, $target_server, $require_active)[0] ?? null;
    }

    private static function blockerMessage(string $reason): string
    {
        return match ($reason) {
            MoveBlockers::APP_NOT_ACTIVE => __('messages.database_migration.not_active'),
            MoveBlockers::SAME_SERVER => __('messages.database_migration.same_server'),
            default => __('messages.database_migration.invalid_server'),
        };
    }

    /**
     * Database servers $app_instance could be moved to.
     */
    public static function targets(AppInstance $app_instance)
    {
        return Server::where('type', 'database')
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $server) => ! self::blocker($app_instance, $server));
    }

    public static function steps(): array
    {
        return [PrepareMigration::class, BackupDatabase::class, RestoreDatabase::class, CutOver::class];
    }

    /**
     * Starts a backup or restore. A driver that does the work before it
     * returns says so ('completed'); one that hands it to another service
     * leaves the record running, to be completed (or failed) by that
     * service's callback.
     *
     * @internal Used by the migration's steps
     */
    public static function launch(OrgBackup $backup, string $method): void
    {
        $response = Backup::connect($backup)->$method();

        if (! empty($response['job_id'])) {
            $backup->job_id = $response['job_id'];
        }

        // Only a result that is already known is written: for the others the
        // callback may have got there first.
        $status = $response['status'] ?? 'running';
        if ($status !== 'running') {
            $backup->status = $status;
            $backup->completed_at = $status === 'completed' ? now() : null;
        }

        $backup->save();
    }

    /**
     * @internal Used by the migration's steps
     */
    public static function record(Task $task, string $action, OrgServer $org_server, array $settings, ?string $backup_name = null): OrgBackup
    {
        $backup = new OrgBackup;
        $backup->organization_id = $task->organization_id;
        $backup->app_instance_id = $task->app_instance_id;
        $backup->org_server_id = $org_server->id;
        $backup->action = $action;
        $backup->type = 'database';
        $backup->source = 'database_migration';
        $backup->status = 'running';
        $backup->scheduled_at = now();
        $backup->backup_name = $backup_name;
        $backup->settings = $settings;
        $backup->save();

        return $backup;
    }

    /**
     * Saves progress on the task straight away, for what a step must not lose
     * if it doesn't get to return.
     *
     * @internal Used by the migration's steps
     */
    public static function remember(Task $task, array $values): void
    {
        $task->custom_values = array_merge($task->customValues(), $values);
        $task->save();
    }
}
