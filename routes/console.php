<?php

use App\Console\Calls\ActivateBackup;
use App\Console\Calls\DeactivatedAppInstanceCheck;
use App\Console\Calls\DeleteBackups;
use App\Console\Calls\ExpiredSubscriptionsCheck;
use App\Console\Calls\NextcloudStorageChecks;
use App\Console\Calls\NotifyBillingManagers;
use App\Console\Calls\PrerequisiteChecks;
use App\Console\Calls\RecurringBackups;
use App\Console\Calls\SystemTasks;
use App\Console\Calls\TaskCleanup;
use App\Integrations\Registrars\Namecheap\Jobs\UpdateTransferStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Spatie\UptimeMonitor\Commands\CheckCertificates;
use Spatie\UptimeMonitor\Commands\CheckUptime;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Each invokable is named so scheduler output and failures can be traced, and
| a heartbeat is logged every minute: alert when it stops arriving, which
| means the scheduler (cron / schedule:work) is down.
*/

$scheduled = fn (object $task, string $name) => Schedule::call($task)
    ->name($name)
    ->onFailure(fn () => Log::error('Scheduled task failed', ['event' => 'scheduler.task_failed', 'task' => $name]));

// Opt in with LOG_HEARTBEAT=true on the scheduler deployment. It goes straight to the
// JSON stderr channel so it never fills the logs table or reaches the alert channels.
Schedule::call(fn () => Log::channel('stderr_json')->info('Scheduler heartbeat', ['event' => 'scheduler.heartbeat']))
    ->name('scheduler-heartbeat')
    ->when(fn () => (bool) config('logging.heartbeat'))
    ->everyMinute();

$scheduled(new TaskCleanup, 'task-cleanup')->everyMinute();
$scheduled(new PrerequisiteChecks, 'prerequisite-checks')->everyMinute();
$scheduled(new SystemTasks, 'system-tasks')->everyMinute();
$scheduled(new RecurringBackups, 'recurring-backups')->everyMinute();
$scheduled(new UpdateTransferStatus, 'update-transfer-status')->everyMinute();
$scheduled(new NextcloudStorageChecks, 'nextcloud-storage-checks')->daily();
$scheduled(new ActivateBackup, 'activate-backup')->everyMinute();
$scheduled(new DeleteBackups, 'delete-backups')->everyMinute();
$scheduled(new NotifyBillingManagers, 'notify-billing-managers')->daily();
$scheduled(new ExpiredSubscriptionsCheck, 'expired-subscriptions-check')->everyMinute();
$scheduled(new DeactivatedAppInstanceCheck, 'deactivated-app-instance-check')->everyMinute();
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('queue:prune-batches')->daily();
Schedule::command('queue:prune-failed', ['--hours' => 720])->daily();
Schedule::command('model:prune', ['--model' => [App\Log::class, App\AuditLog::class]])->daily();
Schedule::command(CheckUptime::class)->everyMinute();
Schedule::command(CheckCertificates::class)->daily();
