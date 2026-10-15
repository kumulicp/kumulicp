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

        if ($current->server_id === $target_server->id) {
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

            // 1. Fresh backup, taken from the app's own database server
            $backup = self::record($task, 'backup', $previous, ['source_org_server_id' => $previous->id]);
            $backup->delete_at = now()->addDays(self::BACKUP_RETENTION_DAYS);
            Backup::connect($backup)->run();
            self::finish($backup);

            // 2. Restore into the new server, which adds the database and user there
            $restore = self::record($task, 'restore', $previous, ['target_org_server_id' => $target->id], $backup->backup_name);
            Backup::connect($restore)->restore();
            self::finish($restore);

            // 3. Cut over
            self::remember($task, ['cutover_at' => now()->toIso8601String()]);
            $app_instance->database_server()->associate($target);
            $app_instance->save();

            // 4. Standard update so the chart gets the new database host. A
            //    stale update from before the cutover would be configured
            //    for the old server.
            ActionFacade::clear('application_update', $app_instance);
            $update = ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);

            if (! $update) {
                throw new \Exception(__('messages.database_migration.update_not_scheduled'));
            }

            self::remember($task, ['update_task_id' => $update->id]);
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
        // Still copying; the app hasn't been pointed at the new server yet.
        if (! $update_id = $task->getValue('update_task_id')) {
            return;
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

            return;
        }

        $timed_out = Carbon::parse($task->getValue('cutover_at'))->addMinutes(self::UPDATE_TIMEOUT_MINUTES)->isPast();

        if (! $update || $update->status === 'failed' || $timed_out) {
            self::rollback($task, $update?->error_message ?: __('messages.database_migration.update_failed'));
        }
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

    private static function finish(OrgBackup $backup): void
    {
        $backup->status = 'completed';
        $backup->completed_at = now();
        $backup->save();
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
