<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Apps\DatabaseMigrate\BackupDatabase;
use App\Actions\Apps\DatabaseMigrate\CutOver;
use App\Actions\Apps\DatabaseMigrate\PrepareMigration;
use App\Actions\Apps\DatabaseMigrate\RestoreDatabase;
use App\Actions\Steps\StepAction;
use App\AppInstance;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Backup;
use App\Task;
use Illuminate\Support\Facades\Log;

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
class ApplicationDatabaseMigrate extends StepAction
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

    public function __construct(AppInstance $app_instance, Server $target_server)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->addCustomValue(['target_server_id' => $target_server->id]);

        $this->description = __('actions.migrating_app_database', [
            'app' => $app_instance->label,
            'server' => $target_server->name,
        ]);

    }

    // Only once the task really exists (not for a duplicate that
    // Action::execute() turns away), and again when it is retried.
    public function onCreate(Task $task)
    {
        $this->app_instance->status = 'updating';
        $this->app_instance->save();
    }

    /**
     * Why this app can't be migrated to $target_server, or null if it can.
     */
    public static function blocker(AppInstance $app_instance, Server $target_server, bool $require_active = true): ?string
    {
        $current = $app_instance->database_server;

        if (! $app_instance->databasename || ! $current) {
            return __('messages.database_migration.no_database');
        }

        if ($require_active && $app_instance->status !== 'active') {
            return __('messages.database_migration.not_active');
        }

        if ($target_server->type !== 'database' || ! $target_server->host || ! $target_server->internal_address) {
            return __('messages.database_migration.invalid_server');
        }

        // Both ends need a backup driver that can work against a named server:
        // the old server is dumped, the new one restored into.
        if (! Backup::supportsServerTargets($target_server) || ! Backup::supportsServerTargets($current->server)) {
            return __('messages.database_migration.invalid_server');
        }

        // Restoring replaces the database it loads into, so a different server
        // record for the same host is just as much the app's live database.
        if ($current->server_id === $target_server->id || $current->server->host === $target_server->host) {
            return __('messages.database_migration.same_server');
        }

        return null;
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

    public static function retry(Task $task): ?Action
    {
        // Starts over; the previous attempt already rolled itself back.
        return new self($task->app_instance, Server::findOrFail($task->getValue('target_server_id')));
    }

    /**
     * Says where the app is back on, and what is left behind on the new server.
     */
    public static function failureMessage(Task $task, string $reason, array $notes, array $errors): string
    {
        if ($errors) {
            $message = __('messages.database_migration.rollback_failed', ['reason' => $reason, 'error' => $errors[0]]);
        } else {
            $previous = OrgServer::find($task->getValue('previous_org_server_id'));

            $message = __('messages.database_migration.rolled_back', [
                'server' => $previous?->server?->name,
                'reason' => $reason,
            ]);

            if ($notes) {
                $message .= ' '.implode(' ', $notes);
            }
        }

        Log::critical($message, ['organization_id' => $task->organization_id]);

        return $message;
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
