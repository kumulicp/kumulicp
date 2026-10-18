<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\OrgBackup;
use App\OrgServer;
use App\Server;
use App\Services\OrgServerService;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Backup;
use App\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * confirmed complete: run() starts the first, and complete(), which the task
 * runner calls every minute, moves on from there.
 *
 * Nothing on the old server is ever modified, so any failure before the app
 * comes back up on the new server is rolled back by pointing the app at the
 * old server again and running another standard update. The old database is
 * left in place afterwards for the same reason (and is the way back if the
 * new server turns out to be a mistake: migrate to the old server again).
 */
class ApplicationDatabaseMigrate extends Action
{
    public $slug = 'application_database_migrate';

    public static bool $long_running = true;

    // How long the app has, after being pointed at the new database, to come
    // back up on it before the migration is rolled back.
    public const UPDATE_TIMEOUT_MINUTES = 20;

    // How long the backup, and then the restore, each have to finish before
    // the migration is given up on (the app is still on the old database then).
    public const COPY_TIMEOUT_MINUTES = 180;

    // Longest a single pass over the migration may hold its lock for: a driver
    // that backs up or restores before it returns can take a while.
    private const LOCK_SECONDS = 14400;

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
    // Action::execute() turns away), and not when run() rebuilds the action.
    public function postGenerate(Task $task)
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

    public static function run(Task $task)
    {
        $app_instance = $task->app_instance;
        $target_server = Server::findOrFail($task->getValue('target_server_id'));

        try {
            if ($blocker = self::blocker($app_instance, $target_server, require_active: false)) {
                throw new \Exception($blocker);
            }

            $previous = $app_instance->database_server;
            $target = OrgServerService::forServer($task->organization, $target_server)->get();

            // Recorded before anything else so a rollback (including one
            // from RunAction::failed() after a crash) knows the way back.
            self::remember($task, [
                'previous_org_server_id' => $previous->id,
                'target_org_server_id' => $target->id,
            ]);

            self::advance($task);
        } catch (Throwable $e) {
            report($e);

            self::rollback($task, $e->getMessage());
        }

        return self::forTask($task);
    }

    public static function retry(Task $task)
    {
        // Starts over; the previous attempt already rolled itself back.
        return new self($task->app_instance, Server::findOrFail($task->getValue('target_server_id')));
    }

    public static function complete(Task &$task)
    {
        try {
            self::advance($task);
        } catch (Throwable $e) {
            report($e);

            self::rollback($task, $e->getMessage());
        }
    }

    /**
     * Moves the migration on as far as it can go right now. run() and the
     * every-minute completion check can overlap, and a step started twice
     * would be a second restore or a second cut over, so only one at a time.
     */
    private static function advance(Task $task): void
    {
        Cache::lock("database-migration:{$task->id}", self::LOCK_SECONDS)->get(function () use ($task) {
            $task->refresh();

            while (self::step($task)) {
                //
            }
        });
    }

    /**
     * Does what the stage the migration is at calls for. True if it moved on
     * to the next stage (which may already be finished, for a driver that
     * completes before returning), false if it has to wait.
     */
    private static function step(Task $task): bool
    {
        if ($task->status !== 'in_progress') {
            return false;
        }

        return match ($task->getValue('stage')) {
            null => self::startBackup($task),
            'backup' => self::startRestore($task),
            'restore' => self::cutOver($task),
            'update' => self::finishUpdate($task),
            default => false,
        };
    }

    // 1. Fresh backup, taken from the app's own database server. A complete
    //    copy: whatever routine backups leave out would be missing from the
    //    new database.
    private static function startBackup(Task $task): bool
    {
        $previous = OrgServer::findOrFail($task->getValue('previous_org_server_id'));

        $backup = self::record($task, 'backup', $previous, ['source_org_server_id' => $previous->id, 'full_copy' => true]);
        $backup->delete_at = now()->addDays(self::BACKUP_RETENTION_DAYS);
        $backup->save();

        self::remember($task, ['stage' => 'backup', 'backup_id' => $backup->id, 'stage_started_at' => now()->toIso8601String()]);
        self::launch($backup, 'run');

        return true;
    }

    // 2. Restore into the new server, which adds the database and user there
    private static function startRestore(Task $task): bool
    {
        $backup = OrgBackup::findOrFail($task->getValue('backup_id'));

        if (! self::finished($task, $backup)) {
            return false;
        }

        if (! $backup->backup_name) {
            throw new \Exception(__('messages.exception.no_backup_file'));
        }

        $previous = OrgServer::findOrFail($task->getValue('previous_org_server_id'));
        $restore = self::record($task, 'restore', $previous, ['target_org_server_id' => $task->getValue('target_org_server_id')], $backup->backup_name);

        self::remember($task, ['stage' => 'restore', 'restore_id' => $restore->id, 'stage_started_at' => now()->toIso8601String()]);
        self::launch($restore, 'restore');

        return true;
    }

    // 3. Cut over: the data is on the new server, so point the app at it.
    //
    // 4. Standard update so the chart gets the new database host. A stale
    //    update from before the cutover would be configured for the old server.
    private static function cutOver(Task $task): bool
    {
        $restore = OrgBackup::findOrFail($task->getValue('restore_id'));

        if (! self::finished($task, $restore)) {
            return false;
        }

        $app_instance = $task->app_instance;

        self::remember($task, ['stage' => 'update', 'cutover_at' => now()->toIso8601String()]);
        $app_instance->database_server()->associate(OrgServer::findOrFail($task->getValue('target_org_server_id')));
        $app_instance->save();

        ActionFacade::clear('application_update', $app_instance);
        $update = ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);

        if (! $update) {
            throw new \Exception(__('messages.database_migration.update_not_scheduled'));
        }

        self::remember($task, ['update_task_id' => $update->id]);

        return false;
    }

    private static function finishUpdate(Task $task): bool
    {
        // Cut over, but the update that applies it hasn't been scheduled yet
        if (! $update_id = $task->getValue('update_task_id')) {
            return false;
        }

        $update = Task::find($update_id);

        if ($update?->status === 'complete') {
            $app_instance = $task->app_instance;
            $app_instance->status = 'active';
            $app_instance->save();

            Log::info(__('messages.database_migration.migrated', [
                'app' => $app_instance->label,
                'server' => $app_instance->database_server->server->name,
            ]), ['organization_id' => $task->organization_id]);

            $task->complete();
            $task->groupNotified();

            return false;
        }

        $timed_out = Carbon::parse($task->getValue('cutover_at'))->addMinutes(self::UPDATE_TIMEOUT_MINUTES)->isPast();

        if (! $update || $update->status === 'failed' || $timed_out) {
            throw new \Exception($update?->error_message ?: __('messages.database_migration.update_failed'));
        }

        return false;
    }

    /**
     * Starts a backup or restore. A driver that does the work before it
     * returns says so ('completed'); one that hands it to another service
     * leaves the record running, to be completed (or failed) by that
     * service's callback.
     */
    private static function launch(OrgBackup $backup, string $method): void
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
     * Whether a backup or restore has finished, so the next stage can start.
     * Throws if it failed, or has been running longer than it should.
     */
    private static function finished(Task $task, OrgBackup $backup): bool
    {
        $backup->refresh();

        if ($backup->status === 'completed') {
            return true;
        }

        if ($backup->status !== 'running') {
            throw new \Exception(__('messages.database_migration.copy_failed', ['step' => __('labels.'.$backup->action)]));
        }

        if (Carbon::parse($task->getValue('stage_started_at'))->addMinutes(self::COPY_TIMEOUT_MINUTES)->isPast()) {
            throw new \Exception(__('messages.database_migration.copy_timed_out', ['step' => __('labels.'.$backup->action)]));
        }

        return false;
    }

    /**
     * Last resort for a run() that died without getting to its own rollback
     * (RunAction::failed() calls this).
     */
    public static function revert(Task $task)
    {
        if ($task->status !== 'complete') {
            self::rollback($task, $task->error_message ?: __('messages.database_migration.update_failed'));
        }
    }

    /**
     * Back to the database server the app was on before. If the app was
     * already pointed at the new server, it is updated again so the chart
     * goes back to the old database. The new server's copy is left alone: the
     * app may still be running against it until that update lands, and it is
     * only ever a copy.
     */
    private static function rollback(Task $task, string $reason): void
    {
        $app_instance = $task->app_instance;
        $previous = OrgServer::find($task->getValue('previous_org_server_id'));
        $target = OrgServer::find($task->getValue('target_org_server_id'));
        $message = $reason;

        try {
            if ($previous && $app_instance->database_server_id !== $previous->id) {
                $app_instance->database_server()->associate($previous);
                $app_instance->status = 'updating';
                $app_instance->save();

                // The update that was trying to land the new database must
                // not block (or be mistaken for) the one that undoes it.
                ActionFacade::clear('application_update', $app_instance);
                ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);
            } else {
                $app_instance->status = 'active';
                $app_instance->save();
            }

            $message = __('messages.database_migration.rolled_back', [
                'server' => $previous?->server?->name,
                'reason' => $reason,
            ]);

            if ($target) {
                $message .= ' '.__('messages.database_migration.copy_left', ['server' => $target->server?->name]);
            }
        } catch (Throwable $e) {
            report($e);

            $message = __('messages.database_migration.rollback_failed', ['reason' => $reason, 'error' => $e->getMessage()]);
        }

        Log::critical($message, ['organization_id' => $task->organization_id]);

        $task->status = 'failed';
        $task->error_message = $message;
        $task->save();
    }

    private static function record(Task $task, string $action, OrgServer $org_server, array $settings, ?string $backup_name = null): OrgBackup
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

    // run() and ActionService::run() both persist custom values when the
    // returned action differs from the task, so progress is saved on the task
    // directly and the action handed back always mirrors it.
    private static function remember(Task $task, array $values): void
    {
        $task->custom_values = array_merge($task->customValues(), $values);
        $task->save();
    }

    private static function forTask(Task $task): self
    {
        $action = new self($task->app_instance, Server::findOrFail($task->getValue('target_server_id')));
        $action->setCustomValues($task->customValues());

        return $action;
    }
}
